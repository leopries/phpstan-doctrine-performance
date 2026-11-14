<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Analyser\Scope;

/** Remembers which foreach loops iterate a statically known number of times. */
final class LoopBounds
{
    private string $file = '';

    /** @var array<int, int|null> */
    private array $bounds = [];

    public function record(Foreach_ $node, Scope $scope): void
    {
        if ($scope->getFile() !== $this->file) {
            $this->file = $scope->getFile();
            $this->bounds = [];
        }

        $type = $scope->getType($node->expr);
        $sizes = [];
        foreach ($type->getConstantArrays() as $array) {
            $sizes[] = \count($array->getKeyTypes());
        }
        $this->bounds[$node->getStartFilePos()] = !$node->byRef && $type->isConstantArray()->yes() && [] !== $sizes ? max($sizes) : null;
    }

    /** @return array{int, int} number of unbounded enclosing loops and the iteration maximum of the bounded ones */
    public function resolve(Node $node, Scope $scope): array
    {
        $depth = $node->getAttribute(LoopDepthVisitor::ATTRIBUTE, 0);
        $loops = $node->getAttribute(LoopDepthVisitor::LOOPS_ATTRIBUTE, []);
        if (!\is_int($depth) || !\is_array($loops) || [] === $loops || $scope->getFile() !== $this->file) {
            return [\is_int($depth) ? $depth : 0, 1];
        }

        $unbounded = 0;
        $maximum = 1;
        foreach ($loops as $loop) {
            $bound = \is_int($loop) ? ($this->bounds[$loop] ?? null) : null;
            if (null === $bound) {
                ++$unbounded;
            } else {
                $maximum *= $bound;
            }
        }

        return [$unbounded, $maximum];
    }
}
