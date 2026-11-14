<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;

/** @extends EntityRepository<Customer> */
class CustomerRepository extends EntityRepository
{
    public function findActive(): array
    {
        return [];
    }
}

class NonDoctrineService
{
    public function find(int $id): void
    {
    }
}

/**
 * @param list<int>                  $ids
 * @param ObjectRepository<Customer> $repository
 */
function queries(array $ids, ObjectRepository $repository, CustomerRepository $customRepository, EntityManagerInterface $manager, Query $query, Connection $connection, Statement $statement, DbalQueryBuilder $dbalBuilder, QueryBuilder $ormBuilder, Result $result, NonDoctrineService $service, ?CustomerRepository $nullableRepository): void
{
    $repository->find(1);
    foreach ($repository->findAll() as $customer) {
        echo $customer->name;
    }
    for ($i = $connection->fetchOne('SELECT 1'); $i < 3; ++$i) {
        echo $i;
    }
    foreach ($ids as $id) {
        $repository->find($id);
        $repository->findOneBy(['id' => $id]);
        $customRepository->findActive();
        $manager->find(Customer::class, $id);
        $query->getResult();
        $connection->executeQuery('SELECT 1');
        $connection->fetchOne('SELECT 1');
        $statement->executeQuery();
        $dbalBuilder->executeQuery();
        $dbalBuilder->fetchAllAssociative();
        $ormBuilder->getQuery()->getArrayResult();
        $connection->update('customers', ['name' => 'name'], ['id' => $id]);
        $service->find($id);
        $result->fetchAssociative();
        $ormBuilder->where('c.id = :id');
        $dbalBuilder->where('id = :id');
        $callable = $repository->find(...);
        $callback = static fn () => $repository->find(1);
        $closure = static function () use ($repository): void {
            $repository->find(1);
        };
        foreach ($repository->findAll() as $customer) {
            $repository->find($id);
        }
        for ($i = $connection->fetchOne('SELECT 1'); $i < 3; ++$i) {
            $query->execute();
        }
    }
    while ($connection->fetchOne('SELECT 1')) {
        break;
    }
    do {
        $statement->executeStatement();
    } while ($connection->fetchOne('SELECT 1'));
    for ($i = 0; $connection->fetchOne('SELECT 1'); $connection->executeStatement('UPDATE customers SET name = name')) {
        break;
    }
    $repository->find(1);
    foreach ($ids as $id) {
        $nullableRepository?->find($id);
        $repository->FIND($id);
        $manager->flush();
    }
    $nullableRepository?->find(1);
}
