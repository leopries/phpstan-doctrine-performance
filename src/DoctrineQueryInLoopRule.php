<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/** @implements Rule<Node> */
final class DoctrineQueryInLoopRule implements Rule
{
    public const string INDEPENDENT = 'Receiver and arguments do not depend on the loop, so every iteration runs the same query: run it once before the loop and reuse the result, unless the loop changes the data it reads.';

    private string $file = '';

    /** @var array<string, true> */
    private array $reported = [];

    public function __construct(
        private readonly AssociationAccess $associations,
        private readonly LoopBounds $loopBounds,
        private readonly QueryCalls $queryCalls,
        private readonly Severity $severity,
    ) {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Foreach_) {
            $this->loopBounds->record($node, $scope);

            return [];
        }

        // PHPStan also visits null-safe accesses as normalized MethodCall/PropertyFetch nodes.
        if (!$node instanceof MethodCall && !$node instanceof PropertyFetch) {
            return [];
        }

        $depth = $node->getAttribute(LoopDepthVisitor::ATTRIBUTE, 0);
        if (!\is_int($depth) || $depth < 1 || !$node->name instanceof Identifier) {
            return [];
        }

        $type = TypeCombinator::removeNull($scope->getType($node->var));
        $name = $node->name->toString();

        if ($node instanceof MethodCall) {
            if ($node->isFirstClassCallable()) {
                return [];
            }

            $query = $this->queryCalls->classify($type, $node, $name, $scope);
            if (null !== $query) {
                // Same wording as the indirect rule, so a query and the loops that reach it through calls group together.
                $function = $scope->getFunction();
                $cause = (null === $function ? $scope->getFile() : ($scope->getClassReflection()?->getName() ?? '').'::'.$function->getName()).' → '.$name.'()';
                [$unbounded, $maximum] = $this->loopBounds->resolve($node, $scope);
                if (0 === $maximum) {
                    return [];
                }
                if (0 === $unbounded) {
                    return $this->errors(RuleErrorBuilder::message(\sprintf(
                        'Doctrine query %s() executes inside statically bounded loops (at most %d iterations in total). Fixed query cost; this is not input-dependent N+1 growth.',
                        $name, $maximum,
                    ))->identifier('doctrine.queryInBoundedLoop'), $this->severity->assess(Severity::BOUNDED, 1, $scope->getFile()), $cause);
                }
                $depth = $unbounded;
                // A flush or a write has its effect in every iteration, whatever its arguments are.
                $independent = QueryCalls::READ === $query['kind'] && !LoopDepthVisitor::dependsOnLoop($node, $scope);

                return $this->errors(RuleErrorBuilder::message(\sprintf(
                    'Doctrine query %s() executes inside %s. %s Batch queries or fetch the data before the loop.',
                    $name,
                    $depth > 1 ? 'nested loops' : 'a loop',
                    $depth > 1 ? 'Potential N×M queries (N² when both dimensions grow with N).' : 'Potential N+1 queries.',
                ))->identifier($depth > 1 ? 'doctrine.queryInNestedLoop' : 'doctrine.queryInLoop'), $this->severity->assess(Severity::kind($query['kind']), $depth, $scope->getFile(), $query['entity'], false, true === $node->getAttribute(LoopDepthVisitor::CONDITIONAL_ATTRIBUTE), $independent), $cause, $independent ? self::INDEPENDENT : null);
            }
        } elseif ($scope->isInExpressionAssign($node)) {
            return [];
        }

        // An association is loaded once per owner: only the loops that change the owner multiply the queries.
        $depth = LoopDepthVisitor::changingDepth($node->var, $node);
        if (0 === $depth) {
            return [];
        }

        foreach ($this->associations->resolve($node, $scope) as [$class, $property, $mapping]) {
            if (!$this->associations->loads($node, $scope, $property, $mapping)) {
                continue;
            }

            // The first access initializes the association; repeating it in the same iteration, or in a later loop over the
            // same entities, costs nothing.
            if ($this->alreadyReported($node, $property, $scope)) {
                return [];
            }

            return $this->errors(RuleErrorBuilder::message(\sprintf(
                'Potential lazy Doctrine association %s::$%s accessed inside %s. Potential %s queries; fetch-join or batch-load the association before the loop.',
                $class->getDisplayName(),
                $property,
                $depth > 1 ? 'nested loops' : 'a loop',
                $depth > 1 ? 'N×M (possibly N²)' : 'N+1',
            ))->identifier('doctrine.lazyAssociationInLoop'), $this->severity->assess(
                $mapping->isToOne() ? Severity::TO_ONE : Severity::TO_MANY, $depth, $scope->getFile(), $mapping->targetEntity,
            ), AssociationAccess::cause($class, $property, $mapping));
        }

        return [];
    }

    /**
     * @param RuleErrorBuilder<IdentifierRuleError>                                           $builder
     * @param array{score: int, level: string, reasons: list<array{int, string}>, test: bool} $assessment
     *
     * @return list<IdentifierRuleError>
     */
    private function errors(RuleErrorBuilder $builder, array $assessment, string $cause, ?string $detail = null): array
    {
        $error = $this->severity->apply($builder, $assessment, $cause, null, $detail);

        return null === $error ? [] : [$error];
    }

    private function alreadyReported(MethodCall|PropertyFetch $node, string $property, Scope $scope): bool
    {
        if ($scope->getFile() !== $this->file) {
            $this->file = $scope->getFile();
            $this->reported = [];
        }

        $receiver = $node->var;
        $path = '';
        while (($receiver instanceof MethodCall || $receiver instanceof PropertyFetch) && $receiver->name instanceof Identifier
            && ($receiver instanceof PropertyFetch || [] === $receiver->getArgs())) {
            $path = '.'.$receiver->name->toString().$path;
            $receiver = $receiver->var;
        }
        if (!$receiver instanceof Variable || !\is_string($receiver->name)) {
            return false;
        }

        $iteration = LoopDepthVisitor::iterationKey($node, $receiver->name);
        $loops = $node->getAttribute(LoopDepthVisitor::LOOPS_ATTRIBUTE, []);
        $key = ($scope->getFunction()?->getName() ?? '').'|'.($iteration ?? (\is_array($loops) ? implode(',', $loops) : '').'|'.$receiver->name).$path.'|'.$property;
        if (isset($this->reported[$key])) {
            return true;
        }
        $this->reported[$key] = true;

        return false;
    }
}
