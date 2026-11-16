<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\TypeCombinator;

/** Recognizes accesses to mapped Doctrine associations and whether they make Doctrine load a lazy one. */
final class AssociationAccess
{
    public function __construct(
        private readonly ObjectMetadataResolver $metadataResolver,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly FetchedAssociations $fetchedAssociations,
    ) {
    }

    /**
     * The mapped associations a property fetch or direct getter call returns, one per entity class of the receiver.
     *
     * @return list<array{ClassReflection, string, AssociationMapping}> owning class, property and mapping
     */
    public function resolve(MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node, Scope $scope): array
    {
        $associations = [];
        if (!$node->name instanceof Identifier) {
            return [];
        }

        foreach (TypeCombinator::removeNull($scope->getType($node->var))->getObjectClassReflections() as $class) {
            $metadata = $this->metadataResolver->getClassMetadata($class->getName());
            if (null === $metadata) {
                continue;
            }

            $property = $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch
                ? $node->name->toString() : $this->fetchedAssociations->getReturnedProperty($class, $node->name->toString());
            if (null !== $property && isset($metadata->associationMappings[$property])) {
                $associations[] = [$class, $property, $metadata->associationMappings[$property]];
            }
        }

        return $associations;
    }

    /**
     * Not a load: eager mappings, receivers that were fetch-joined, associations the code itself put into the object
     * earlier in the iteration, and uses of only the reference or the identifier.
     */
    public function loads(MethodCall|PropertyFetch $node, Scope $scope, string $property, AssociationMapping $mapping): bool
    {
        return ClassMetadata::FETCH_EAGER !== $mapping->fetch
            && !$this->fetchedAssociations->isFetched($node->var, $property, $scope)
            && !\in_array($property, $this->assignedProperties($node->var, $node, $scope), true)
            && !$this->onlyUsesReferenceOrIdentifier($node, $scope, $mapping->targetEntity, $mapping->isToOne());
    }

    /**
     * The associations of an object that hold what the code put there, not a lazy proxy: all of them for an object
     * created with `new`, otherwise those assigned directly or through a setter earlier in the same iteration.
     *
     * @return list<string>
     */
    public function assignedProperties(Expr $object, Node $annotated, Scope $scope): array
    {
        $classes = TypeCombinator::removeNull($scope->getType($object))->getObjectClassReflections();
        if ($object instanceof New_) {
            $members = ['*'];
        } else {
            $members = LoopDepthVisitor::assignedMembers($annotated, $object, LoopDepthVisitor::changingDepth($object, $annotated));
        }

        $properties = [];
        foreach ($members as $member) {
            if (str_starts_with($member, 'property:')) {
                $properties[] = substr($member, 9);
                continue;
            }
            foreach ($classes as $class) {
                if ('*' === $member) {
                    array_push($properties, ...array_keys($this->metadataResolver->getClassMetadata($class->getName())->associationMappings ?? []));
                } elseif (str_starts_with($member, 'method:')) {
                    array_push($properties, ...$this->fetchedAssociations->getAssignedProperties($class, substr($member, 7)));
                }
            }
        }

        return array_values(array_unique($properties));
    }

    /** What the findings of one association are grouped by, whichever rule reports them. */
    public static function cause(ClassReflection $class, string $property, AssociationMapping $mapping): string
    {
        return $class->getName().'::$'.$property.' (lazy '.($mapping->isToOne() ? 'to-one' : 'to-many').')';
    }

    /**
     * The variable an expression starts from and the to-one associations in between: `$order->getCustomer()` is ['order', 'customer.'].
     *
     * @return array{string, string}|null
     */
    public function root(Expr $expr, Scope $scope): ?array
    {
        if ($expr instanceof Variable) {
            return \is_string($expr->name) ? [$expr->name, ''] : null;
        }

        if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall && !$expr instanceof PropertyFetch && !$expr instanceof NullsafePropertyFetch) {
            return null;
        }

        $root = $this->root($expr->var, $scope);
        $association = null === $root ? null : ($this->resolve($expr, $scope)[0] ?? null);

        return null !== $root && null !== $association && $association[2]->isToOne() ? [$root[0], $root[1].$association[1].'.'] : null;
    }

    /** The position of a variable among the parameters of the enclosing method. */
    public static function parameter(string $variable, Scope $scope): ?int
    {
        foreach ($scope->getAnonymousFunctionReflection()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $variable) {
                return null;
            }
        }
        // shortcut: a parameter that the method reassigns still counts as the caller's argument.
        foreach ($scope->getFunction()?->getVariants()[0]->getParameters() ?? [] as $index => $parameter) {
            if ($parameter->getName() === $variable) {
                return $index;
            }
        }

        return null;
    }

    /** @param class-string $targetEntity */
    private function onlyUsesReferenceOrIdentifier(Node $node, Scope $scope, string $targetEntity, bool $toOne): bool
    {
        $uses = $node->getAttribute(LoopDepthVisitor::USES_ATTRIBUTE, ['unknown']);
        if (!\is_array($uses)) {
            return false;
        }

        $metadata = $this->metadataResolver->getClassMetadata($targetEntity);
        foreach ($uses as $use) {
            if (!\is_string($use)) {
                return false;
            }

            if ('reference' === $use) {
                continue;
            }

            if (str_starts_with($use, 'array:') && $scope->hasVariableType(substr($use, 6))->yes()
                && $scope->getVariableType(substr($use, 6))->isArray()->yes()) {
                continue;
            }

            if (!$toOne || null === $metadata) {
                return false;
            }

            $property = null;
            if (str_starts_with($use, 'property:')) {
                $property = substr($use, 9);
            } elseif (str_starts_with($use, 'method:') && $this->reflectionProvider->hasClass($targetEntity)) {
                $property = $this->fetchedAssociations->getReturnedProperty($this->reflectionProvider->getClass($targetEntity), substr($use, 7));
            }

            if (null === $property || !$metadata->isIdentifier($property) || $metadata->hasAssociation($property)) {
                return false;
            }
        }

        return true;
    }
}
