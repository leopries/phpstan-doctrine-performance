<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\TypeCombinator;

/**
 * Records, per method, the Doctrine queries it runs and the project methods it calls, with their loop depth
 * and whether a call in a loop depends on what the loop changes.
 * The data is per file, so PHPStan's result cache and parallel workers keep working for the cross-file rule.
 *
 * @phpstan-type Guard array{index: int, name: string, value: bool, default: bool|null}
 * @phpstan-type Call array{caller: string, line: int, depth: int, callees: list<string>, query: string|null, kind: string|null, entity: string|null, guards: list<Guard>, args: array<int|string, bool|null>|null, conditional: bool, dependent: bool}
 *
 * @implements Collector<CallLike, Call>
 */
final class CallGraphCollector implements Collector
{
    public function __construct(
        private readonly LoopBounds $loopBounds,
        private readonly QueryCalls $queryCalls,
        private readonly ProjectCallees $callees,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if ((!$node instanceof MethodCall && !$node instanceof StaticCall) || !$node->name instanceof Identifier || $node->isFirstClassCallable()) {
            return null;
        }

        $function = $scope->getFunction();
        $class = $scope->getClassReflection();
        if (!$function instanceof MethodReflection || null === $class) {
            return null;
        }

        [$depth, $maximum] = $this->loopBounds->resolve($node, $scope);
        if (0 === $maximum) {
            return null;
        }

        $name = $node->name->toString();
        $call = ['caller' => $class->getName().'::'.$function->getName(), 'line' => $node->getStartLine(), 'depth' => $depth, 'callees' => [], 'query' => null, 'kind' => null, 'entity' => null,
            'guards' => $this->guards($node, $function), 'args' => $this->arguments($node, $scope),
            'conditional' => true === $node->getAttribute(LoopDepthVisitor::CONDITIONAL_ATTRIBUTE),
            // Only the reported call inside a loop is classified; what the callee does with its arguments is not followed.
            'dependent' => 0 === $depth || LoopDepthVisitor::dependsOnLoop($node, $scope),
        ];

        if ($node instanceof MethodCall) {
            $type = TypeCombinator::removeNull($scope->getType($node->var));
            $query = $this->queryCalls->classify($type, $node, $name, $scope);
            if (null !== $query) {
                return ['query' => $name, 'kind' => $query['kind'], 'entity' => $query['entity']] + $call;
            }
        }
        $call['callees'] = array_map(ProjectCallees::name(...), $this->callees->resolve($node, $scope));

        return [] === $call['callees'] ? null : $call;
    }

    /**
     * Boolean parameters of the enclosing method that decide whether this call runs, as in `if ($flush)`.
     *
     * @return list<Guard>
     */
    private function guards(MethodCall|StaticCall $node, MethodReflection $function): array
    {
        $guards = [];
        $attribute = $node->getAttribute(LoopDepthVisitor::GUARDS_ATTRIBUTE, []);
        foreach (\is_array($attribute) ? $attribute : [] as $guard) {
            foreach ($function->getVariants()[0]->getParameters() as $index => $parameter) {
                if (\is_array($guard) && $parameter->getName() === ($guard[0] ?? null) && \is_bool($guard[1] ?? null) && $parameter->getType()->isBoolean()->yes()) {
                    $default = $parameter->getDefaultValue();
                    $guards[] = ['index' => $index, 'name' => $parameter->getName(), 'value' => $guard[1], 'default' => match (true) {
                        null === $default => null,
                        $default->isTrue()->yes() => true,
                        $default->isFalse()->yes() => false,
                        default => null,
                    }];
                }
            }
        }

        return $guards;
    }

    /**
     * The arguments that are literally true or false, by position or name; null when unpacking hides the positions.
     *
     * @return array<int|string, bool|null>|null
     */
    private function arguments(MethodCall|StaticCall $node, Scope $scope): ?array
    {
        $arguments = [];
        foreach ($node->getArgs() as $index => $argument) {
            if ($argument->unpack) {
                return null;
            }
            $type = $scope->getType($argument->value);
            $arguments[$argument->name?->toString() ?? $index] = match (true) {
                $type->isTrue()->yes() => true,
                $type->isFalse()->yes() => false,
                default => null,
            };
        }

        return $arguments;
    }
}
