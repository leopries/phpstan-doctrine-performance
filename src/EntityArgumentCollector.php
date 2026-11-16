<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Records calls to project methods that hand an entity on: a parameter of the calling method (the callee's loads
 * become the caller's), or an entity that changes with an enclosing loop (the call site of a potential finding).
 *
 * @phpstan-type Argument array{depth: int, fetched: list<string>, param: int|null, variable: string|null, iteration: string|null, path: string}
 * @phpstan-type Call array{caller: string|null, line: int, loops: list<int>, callees: list<string>, conditional: bool, args: array<int, Argument>}
 *
 * @implements Collector<CallLike, Call>
 */
final class EntityArgumentCollector implements Collector
{
    public function __construct(
        private readonly AssociationAccess $associations,
        private readonly ProjectCallees $callees,
        private readonly FetchedAssociations $fetchedAssociations,
        private readonly LoopBounds $loopBounds,
        private readonly ObjectMetadataResolver $metadataResolver,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if ((!$node instanceof MethodCall && !$node instanceof StaticCall && !$node instanceof New_) || $node->isFirstClassCallable() || [] === $node->getArgs()) {
            return null;
        }

        $callees = $this->callees->resolve($node, $scope);
        if ([] === $callees) {
            return null;
        }

        // shortcut: LoopDepthVisitor does not annotate `new`, so a constructor call is never a loop site, only a pass-through.
        $loops = $this->loopBounds->resolve($node, $scope)[0];
        $function = $scope->getFunction();
        $parameters = $callees[0]->getVariants()[0]->getParameters();
        $arguments = [];
        foreach (array_values($node->getArgs()) as $index => $argument) {
            if ($argument->unpack) {
                break;
            }
            if (null !== $argument->name) {
                $index = null;
                foreach ($parameters as $position => $parameter) {
                    $index = $parameter->getName() === $argument->name->toString() ? $position : $index;
                }
            }

            $type = TypeCombinator::removeNull($scope->getType($argument->value));
            if (null === $index || !$this->isEntity($type)) {
                continue;
            }

            $root = $this->associations->root($argument->value, $scope);
            $param = null !== $root && $function instanceof MethodReflection ? AssociationAccess::parameter($root[0], $scope) : null;
            $depth = min($loops, LoopDepthVisitor::changingDepth($argument->value, $node));
            if (null !== $param || $depth > 0) {
                $arguments[$index] = [
                    'depth' => $depth,
                    // Associations the calling method put into the entity itself load nothing either.
                    'fetched' => $depth > 0 ? array_values(array_unique([...$this->fetched($argument->value, $type, $scope), ...$this->associations->assignedProperties($argument->value, $node, $scope)])) : [],
                    'param' => $param, 'variable' => $root[0] ?? null,
                    'iteration' => null === $root ? null : LoopDepthVisitor::iterationKey($node, $root[0]), 'path' => $root[1] ?? '',
                ];
            }
        }

        $class = $scope->getClassReflection();

        return [] === $arguments ? null : [
            'caller' => $function instanceof MethodReflection && null !== $class ? $class->getName().'::'.$function->getName() : null,
            'line' => $node->getStartLine(),
            'loops' => \is_array($ids = $node->getAttribute(LoopDepthVisitor::LOOPS_ATTRIBUTE, [])) ? array_values(array_filter($ids, is_int(...))) : [],
            'callees' => array_map(ProjectCallees::name(...), $callees),
            'conditional' => true === $node->getAttribute(LoopDepthVisitor::CONDITIONAL_ATTRIBUTE),
            'args' => $arguments,
        ];
    }

    private function isEntity(Type $type): bool
    {
        foreach ($type->getObjectClassReflections() as $class) {
            if (null !== $this->metadataResolver->getClassMetadata($class->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * The association paths the calling method is known to have fetch-joined on the argument, as `customer` or `customer.region`.
     *
     * @return list<string>
     */
    private function fetched(Expr $argument, Type $type, Scope $scope, string $prefix = ''): array
    {
        $fetched = [];
        foreach ($type->getObjectClassReflections() as $class) {
            foreach ($this->metadataResolver->getClassMetadata($class->getName())->associationMappings ?? [] as $property => $mapping) {
                if (!$this->fetchedAssociations->isFetched($argument, $property, $scope)) {
                    continue;
                }
                $fetched[] = $prefix.$property;
                if ('' === $prefix && $mapping->isToOne()) {
                    array_push($fetched, ...$this->fetched(new PropertyFetch($argument, $property), new ObjectType($mapping->targetEntity), $scope, $property.'.'));
                }
            }
        }

        return $fetched;
    }
}
