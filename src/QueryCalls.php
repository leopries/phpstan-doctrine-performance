<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** Recognizes Doctrine calls that hit the database. */
final class QueryCalls
{
    public const string READ = 'read';
    public const string WRITE = 'write';
    public const string FLUSH = 'flush';

    /**
     * @return array{kind: self::READ|self::WRITE|self::FLUSH, entity: string|null}|null
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

            // The template is called T or TEntityClass depending on the phpstan-doctrine version; read it by position.
            $repository = ($type->getObjectClassReflections()[0] ?? null)?->getAncestorWithClassName(ObjectRepository::class);
            $entity = array_values($repository?->getActiveTemplateTypeMap()->getTypes() ?? [])[0] ?? null;

            return ['kind' => self::READ, 'entity' => $entity?->getObjectClassNames()[0] ?? null];
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
                ? ($scope->getType($argument->value)->getClassStringObjectType()->getObjectClassNames()[0] ?? null)
                : null;

            return ['kind' => self::READ, 'entity' => $entity];
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

            return str_starts_with($method, 'fetch') || str_starts_with($method, 'iterate') || \in_array($method, ['executequery', 'executecachequery'], true)
                ? ['kind' => self::READ, 'entity' => null] : null;
        }

        if ((new ObjectType(Statement::class))->isSuperTypeOf($type)->yes() && \in_array($method, ['executequery', 'executestatement'], true)) {
            return ['kind' => 'executestatement' === $method ? self::WRITE : self::READ, 'entity' => null];
        }

        return null;
    }
}
