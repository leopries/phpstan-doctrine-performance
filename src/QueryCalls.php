<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** Recognizes Doctrine calls that hit the database; reads served by a Doctrine cache do not count. */
final class QueryCalls
{
    public const string READ = 'read';
    public const string WRITE = 'write';
    public const string FLUSH = 'flush';
    /** find() by identifier: the identity map answers it for rows already loaded. */
    public const string FIND = 'find';

    public function __construct(
        private readonly ObjectMetadataResolver $metadataResolver,
        private readonly ProjectCallees $callees,
    ) {
    }

    /**
     * @return array{kind: self::READ|self::FIND|self::WRITE|self::FLUSH, entity: string|null}|null
     */
    public function classify(Type $type, MethodCall $call, string $name, Scope $scope): ?array
    {
        if ([] === $type->getObjectClassNames()) {
            return null;
        }

        $method = strtolower($name);
        if ((new ObjectType(ObjectRepository::class))->isSuperTypeOf($type)->yes()) {
            if (!str_starts_with($method, 'find') && 'count' !== $method && 'matching' !== $method) {
                return null;
            }
            // A finder written in the project is followed into its body, where a result cache can be enabled.
            if ([] !== $this->callees->resolve($call, $scope)) {
                return null;
            }

            // The template is called T or TEntityClass depending on the phpstan-doctrine version; read it by position.
            $repository = ($type->getObjectClassReflections()[0] ?? null)?->getAncestorWithClassName(ObjectRepository::class);
            $entity = (array_values($repository?->getActiveTemplateTypeMap()->getTypes() ?? [])[0] ?? null)?->getObjectClassReflections()[0] ?? null;
            // The second-level cache answers find(), findAll(), findBy() and findOneBy(), magic finders included.
            $secondLevel = 'find' === $method || 'findall' === $method || str_starts_with($method, 'findby') || str_starts_with($method, 'findoneby');

            if ($secondLevel && $this->isSecondLevelCached($entity)) {
                return null;
            }

            // A lock mode makes find() query even for an entity in the identity map.
            return ['kind' => 'find' === $method && \count($call->getArgs()) <= 1 ? self::FIND : self::READ, 'entity' => $entity?->getName()];
        }

        if ((new ObjectType(ObjectManager::class))->isSuperTypeOf($type)->yes()) {
            if ('flush' === $method) {
                return ['kind' => self::FLUSH, 'entity' => null];
            }
            if (!\in_array($method, ['find', 'refresh', 'initializeobject'], true)) {
                return null;
            }

            $argument = $call->getArgs()[0] ?? null;
            $entity = 'find' === $method && $argument instanceof Arg
                ? ($scope->getType($argument->value)->getClassStringObjectType()->getObjectClassReflections()[0] ?? null)
                : null;

            if ($this->isSecondLevelCached($entity)) {
                return null;
            }

            return ['kind' => 'find' === $method && \count($call->getArgs()) <= 2 ? self::FIND : self::READ, 'entity' => $entity?->getName()];
        }

        if (true === $call->var->getAttribute(CachedQueryVisitor::ATTRIBUTE)) {
            return null;
        }

        if ((new ObjectType(AbstractQuery::class))->isSuperTypeOf($type)->yes()) {
            return \in_array($method, ['execute', 'getresult', 'getarrayresult', 'getscalarresult', 'getsingleresult', 'getoneornullresult', 'getsinglescalarresult', 'getsinglecolumnresult', 'toiterable'], true)
                ? ['kind' => self::READ, 'entity' => null] : null;
        }

        $connection = (new ObjectType(Connection::class))->isSuperTypeOf($type)->yes();
        if ($connection || (new ObjectType(DbalQueryBuilder::class))->isSuperTypeOf($type)->yes()) {
            if ('executestatement' === $method || ($connection && \in_array($method, ['insert', 'update', 'delete'], true))) {
                return ['kind' => self::WRITE, 'entity' => null];
            }

            // executeCacheQuery() and executeQuery() with a QueryCacheProfile read through the result cache.
            if ('executecachequery' === $method || ($connection && 'executequery' === $method && self::passesProfile($call))) {
                return null;
            }

            return str_starts_with($method, 'fetch') || str_starts_with($method, 'iterate') || 'executequery' === $method
                ? ['kind' => self::READ, 'entity' => null] : null;
        }

        if ((new ObjectType(Statement::class))->isSuperTypeOf($type)->yes() && \in_array($method, ['executequery', 'executestatement'], true)) {
            return ['kind' => 'executestatement' === $method ? self::WRITE : self::READ, 'entity' => null];
        }

        return null;
    }

    /** An entity marked #[ORM\Cache] is read from the second-level cache. */
    private function isSecondLevelCached(?ClassReflection $entity): bool
    {
        return null !== $entity && null !== $this->metadataResolver->getClassMetadata($entity->getName())?->cache;
    }

    private static function passesProfile(MethodCall $call): bool
    {
        foreach ($call->getArgs() as $index => $argument) {
            if (3 === $index || 'qcp' === $argument->name?->toString()) {
                return !$argument->value instanceof ConstFetch || 'null' !== strtolower($argument->value->name->toString());
            }
        }

        return false;
    }
}
