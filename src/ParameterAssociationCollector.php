<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;

/**
 * Records the lazy associations a method reads on its own parameters, as in `map(Order $order)` calling
 * `$order->getCustomer()->getName()`. A loop that calls such a method loads the association once per iteration.
 * Reads on any variable inside a loop are recorded as well: they are what the direct rule already reports.
 *
 * @phpstan-type Read array{method: string, param: int|null, variable: string, iteration: string|null, loops: list<int>, path: string, cause: string, toOne: bool, target: string, line: int, conditional: bool, conditions: list<array{string, bool, list<string>}>}
 *
 * @implements Collector<Expr, Read>
 */
final class ParameterAssociationCollector implements Collector
{
    public function __construct(
        private readonly AssociationAccess $associations,
        private readonly ObjectMetadataResolver $metadataResolver,
    ) {
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        // PHPStan also visits null-safe accesses as normalized MethodCall/PropertyFetch nodes.
        if ((!$node instanceof MethodCall && !$node instanceof PropertyFetch)
            || ($node instanceof MethodCall ? $node->isFirstClassCallable() : $scope->isInExpressionAssign($node))) {
            return null;
        }

        $function = $scope->getFunction();
        $class = $scope->getClassReflection();
        $root = $function instanceof MethodReflection && null !== $class ? $this->associations->root($node->var, $scope) : null;
        $param = null === $root ? null : AssociationAccess::parameter($root[0], $scope);
        // Inside a loop the direct rule reports the access; it is recorded so the same load is not reported again behind a call.
        $loops = $node->getAttribute(LoopDepthVisitor::LOOPS_ATTRIBUTE, []);
        $loops = \is_array($loops) ? array_values(array_filter($loops, is_int(...))) : [];
        $association = null === $root || (null === $param && [] === $loops) ? null : ($this->associations->resolve($node, $scope)[0] ?? null);
        // Entities are not followed as callees: their own accesses are the direct rule's business.
        if (null === $function || null === $class || null === $root || null === $association || null !== $this->metadataResolver->getClassMetadata($class->getName())) {
            return null;
        }

        [$owner, $property, $mapping] = $association;
        if (!$this->associations->loads($node, $scope, $property, $mapping)) {
            return null;
        }

        // State conditions on the parameter are for the caller to decide: it may have set the state before the call.
        $conditions = null === $param ? [] : $this->associations->openConditions($node, $scope, $root[0]);

        return [
            'method' => $class->getName().'::'.$function->getName(), 'param' => $param, 'variable' => $root[0], 'iteration' => LoopDepthVisitor::iterationKey($node, $root[0]), 'loops' => $loops, 'path' => $root[1].$property,
            'cause' => AssociationAccess::cause($owner, $property, $mapping),
            'toOne' => $mapping->isToOne(), 'target' => $mapping->targetEntity, 'line' => $node->getStartLine(),
            'conditional' => true === $node->getAttribute(LoopDepthVisitor::CONDITIONAL_ATTRIBUTE) || [] !== $conditions,
            'conditions' => $conditions,
        ];
    }
}
