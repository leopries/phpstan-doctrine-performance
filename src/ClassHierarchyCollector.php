<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Node\InClassNode;

/**
 * Records each class with its ancestors, so calls through interfaces and parent classes reach the implementations.
 *
 * @implements Collector<InClassNode, array{string, list<string>}>
 */
final class ClassHierarchyCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();
        if ($class->isAnonymous() || $class->isInterface()) {
            return null;
        }

        $ancestors = [];
        foreach ($class->getAncestors() as $ancestor) {
            if ($ancestor->getName() !== $class->getName() && !$ancestor->isBuiltin() && !$ancestor->isTrait()) {
                $ancestors[] = $ancestor->getName();
            }
        }

        return [] === $ancestors ? null : [$class->getName(), $ancestors];
    }

    /** @return array<string, list<string>> the collected classes below each ancestor */
    public static function descendants(CollectedDataNode $node): array
    {
        $descendants = [];
        foreach ($node->get(self::class) as $classes) {
            foreach ($classes as [$class, $ancestors]) {
                foreach ($ancestors as $ancestor) {
                    $descendants[$ancestor][] = $class;
                }
            }
        }

        return $descendants;
    }

    /**
     * The method a call names plus its overrides in subclasses and implementations, as far as they are known to the caller.
     *
     * @param array<string, list<string>> $descendants
     * @param callable(string): bool      $known
     *
     * @return list<string>
     */
    public static function targets(string $callee, array $descendants, callable $known, int $maxImplementations): array
    {
        [$class, $method] = explode('::', $callee, 2);
        $targets = $known($callee) ? [$callee] : [];
        foreach ($descendants[$class] ?? [] as $descendant) {
            if ($known($descendant.'::'.$method)) {
                $targets[] = $descendant.'::'.$method;
            }
        }

        // shortcut: widely implemented interfaces (handlers, listeners) say nothing about a single call; skip them.
        return \count($targets) > $maxImplementations ? [] : $targets;
    }
}
