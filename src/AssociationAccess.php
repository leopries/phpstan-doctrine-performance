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
            && !$this->unreachable($node, $scope)
            && !$this->onlyUsesReferenceOrIdentifier($node, $scope, $mapping->targetEntity, $mapping->isToOne());
    }

    /**
     * The constants the code put into the properties of an object before an annotated node, through a setter or an
     * assignment, in the same iteration of the loops that change the object: `$membership->setState(State::Invited)`.
     *
     * @return array<string, string> property => constant, see LoopDepthVisitor::constant()
     */
    public function states(Expr $object, Node $annotated, Scope $scope): array
    {
        $path = LoopDepthVisitor::receiverPath($object);
        $assigned = $annotated->getAttribute(LoopDepthVisitor::ASSIGNED_ATTRIBUTE, []);
        if (null === $path || !\is_array($assigned)) {
            return [];
        }

        $classes = TypeCombinator::removeNull($scope->getType($object))->getObjectClassReflections();
        $depth = LoopDepthVisitor::changingDepth($object, $annotated);
        $states = [];
        foreach ($assigned as $assignment) {
            if (!\is_array($assignment) || $assignment[0] !== $path || $assignment[2] < $depth || !\is_string($assignment[4] ?? null)) {
                continue;
            }
            $property = $this->writtenProperty($assignment[1], $assignment[5], $classes);
            if (null !== $property) {
                $states[$property] = $assignment[4];
            }
        }

        return $states;
    }

    /**
     * The conditions on a variable's state that an annotated node runs behind and that the known state does not
     * decide yet: what a caller has to set for the node to be skipped.
     *
     * @return list<array{string, bool, list<string>}> property, whether the value has to be one of the constants, constants
     */
    public function openConditions(Node $annotated, Scope $scope, string $variable): array
    {
        $open = [];
        foreach ($this->conditions($annotated, $scope) as [$path, $property, $in, $values, $state, $fresh]) {
            if ($path === $variable && null === $state && $fresh) {
                $open[] = [$property, $in, $values];
            }
        }

        return $open;
    }

    /**
     * Whether a known state rules out every way to a node: `$membership->setState(State::Invited)` before
     * `if ($membership->getState() === State::Active) { … }`.
     *
     * @param array<string, string>                  $states     property => constant
     * @param list<array{string, bool, list<string>}> $conditions see openConditions()
     */
    public static function excluded(array $states, array $conditions): bool
    {
        foreach ($conditions as [$property, $in, $values]) {
            if (isset($states[$property]) && $in !== \in_array($states[$property], $values, true)) {
                return true;
            }
        }

        return false;
    }

    private function unreachable(Node $annotated, Scope $scope): bool
    {
        foreach ($this->conditions($annotated, $scope) as [, , $in, $values, $state]) {
            if (null !== $state && $in !== \in_array($state, $values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The state conditions of an annotated node on plain variables, with the property they test and its known value.
     *
     * @return list<array{string, string, bool, list<string>, string|null, bool}> variable, property, whether the value has to be one of
     *                                                                      the constants, constants, known value, whether a caller's state still holds
     */
    private function conditions(Node $annotated, Scope $scope): array
    {
        $conditions = $annotated->getAttribute(LoopDepthVisitor::CONDITIONS_ATTRIBUTE, []);
        $resolved = [];
        foreach (\is_array($conditions) ? $conditions : [] as $condition) {
            // shortcut: only conditions on a variable itself, not on an object reached from it.
            if (!\is_array($condition) || str_contains($condition[0], '.') || !$scope->hasVariableType($condition[0])->yes()) {
                continue;
            }
            $variable = new Variable($condition[0]);
            $classes = TypeCombinator::removeNull($scope->getVariableType($condition[0]))->getObjectClassReflections();
            $property = $this->readProperty($condition[1], $classes);
            if (null !== $property) {
                $resolved[] = [$condition[0], $property, $condition[2], $condition[3], $this->states($variable, $annotated, $scope)[$property] ?? null, true === ($condition[4] ?? false)];
            }
        }

        return $resolved;
    }

    /**
     * The property a setter or an assignment writes, the same for every class the object may have.
     *
     * @param list<string>          $via     calls before it in a fluent chain, which have to return `$this`
     * @param list<ClassReflection> $classes
     */
    private function writtenProperty(string $member, array $via, array $classes): ?string
    {
        if (str_starts_with($member, 'property:')) {
            return [] === $via ? substr($member, 9) : null;
        }

        $property = null;
        foreach ($classes as $class) {
            $properties = str_starts_with($member, 'method:') ? $this->fetchedAssociations->getAssignedProperties($class, substr($member, 7)) : [];
            if (1 !== \count($properties) || (null !== $property && $property !== $properties[0])
                || !array_all($via, fn (string $method): bool => $this->fetchedAssociations->returnsThis($class, $method))) {
                return null;
            }
            $property = $properties[0];
        }

        return $property;
    }

    /**
     * The property a getter or a property fetch reads, the same for every class the object may have.
     *
     * @param list<ClassReflection> $classes
     */
    private function readProperty(string $member, array $classes): ?string
    {
        if (str_starts_with($member, 'property:')) {
            return substr($member, 9);
        }

        $property = null;
        foreach ($classes as $class) {
            $read = str_starts_with($member, 'method:') ? $this->fetchedAssociations->getReturnedProperty($class, substr($member, 7)) : null;
            if (null === $read || (null !== $property && $property !== $read)) {
                return null;
            }
            $property = $read;
        }

        return $property;
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
