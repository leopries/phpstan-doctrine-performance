<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\ValueFlow;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\CustomerRepository;

final class Filter
{
    public int $regionId = 0;

    public function getRegionId(): int
    {
        return $this->regionId;
    }

    public function next(): int
    {
        return ++$this->regionId;
    }
}

final class Service
{
    private int $current = 0;

    public function __construct(private CustomerRepository $repository, private EntityManagerInterface $manager, private Connection $connection)
    {
    }

    /** @param list<int> $ids */
    public function independent(array $ids, Filter $filter, string $name): void
    {
        foreach ($ids as $id) {
            $this->repository->findBy(['name' => $name]);
            $this->repository->findOneBy(['region' => $filter->getRegionId(), 'name' => strtolower($name)]);
            $this->manager->getRepository(Customer::class)->findAll();
            $this->manager->createQueryBuilder()->select('c')->from(Customer::class, 'c')->getQuery()->getResult();
            foreach ($ids as $other) {
                $this->connection->fetchOne('SELECT 1');
            }
        }
        array_map(fn (int $id): array => $this->repository->findAll(), $ids);
    }

    /**
     * @param list<int> $ids
     * @param list<int> $queue
     */
    public function dependent(array $ids, Filter $filter, QueryBuilder $builder, array $queue, callable $callback): void
    {
        $offset = 0;
        foreach ($ids as $key => $id) {
            $this->repository->find($id);
            $this->repository->findBy(['id' => $ids[$key]]);
            $this->repository->findBy(['id' => $offset]);
            ++$offset;
            // Calls on a local object or with it as argument may change it.
            $builder->setParameter('id', 1);
            $builder->getQuery()->getResult();
            $this->repository->findBy(['region' => $filter->next()]);
            $this->repository->find(array_shift($queue));
            $this->repository->findBy(['id' => $callback()]);
            $this->repository->findBy(['id' => array_map(static fn (int $value): int => $value, $ids)]);
            $this->current = $id;
            $this->repository->find($this->current);
            $this->manager->flush();
            $this->connection->executeStatement('DELETE FROM customers WHERE id = 1');
        }
        // The condition decides when the loop ends, so the body is expected to change its result.
        while ([] !== $this->repository->findBy(['name' => 'pending'])) {
            $this->repository->findBy(['name' => 'pending']);
        }
    }
}
