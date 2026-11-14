<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\TypeCombinator;

/** Resolves a call to the project methods it can reach, which is where the cross-file rules continue. */
final class ProjectCallees
{
    /** @var array<string, bool> */
    private array $skippedClasses = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ObjectMetadataResolver $metadataResolver,
    ) {
    }

    /** @return list<MethodReflection> */
    public function resolve(MethodCall|StaticCall|New_ $node, Scope $scope): array
    {
        $name = $node instanceof New_ ? '__construct' : ($node->name instanceof Identifier ? $node->name->toString() : null);
        if (null === $name) {
            return [];
        }

        if ($node instanceof MethodCall) {
            $classes = TypeCombinator::removeNull($scope->getType($node->var))->getObjectClassReflections();
        } elseif ($node->class instanceof Name) {
            $className = $scope->resolveName($node->class);
            $classes = $this->reflectionProvider->hasClass($className) ? [$this->reflectionProvider->getClass($className)] : [];
        } else {
            $classes = $node->class instanceof Expr ? $scope->getType($node->class)->getObjectTypeOrClassStringObjectType()->getObjectClassReflections() : [];
        }

        $callees = [];
        foreach ($classes as $receiver) {
            if (!$receiver->hasMethod($name)) {
                continue;
            }

            $method = $receiver->getMethod($name, $scope);
            $declaring = $method->getDeclaringClass();
            // Vendor code and entities are not followed: their bodies are either unknown or covered by the association rule.
            if (!($this->skippedClasses[$declaring->getName()] ??= $declaring->isBuiltin() || null === $declaring->getFileName()
                || str_contains(str_replace('\\', '/', $declaring->getFileName()), '/vendor/')
                || null !== $this->metadataResolver->getClassMetadata($declaring->getName()))) {
                $callees[] = $method;
            }
        }

        return $callees;
    }

    /** How the collectors and the rules that read their data name a method. */
    public static function name(MethodReflection $method): string
    {
        return $method->getDeclaringClass()->getName().'::'.$method->getName();
    }
}
