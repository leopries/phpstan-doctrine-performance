<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Indirect;

use Doctrine\ORM\EntityManagerInterface;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer;

final class CustomerLookup
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    public function byId(int $id): ?Customer
    {
        return $this->manager->find(Customer::class, $id);
    }

    public function cached(int $id): ?Customer
    {
        return $this->manager->find(Customer::class, $id);
    }

    public function save(Customer $customer): void
    {
        $this->manager->persist($customer);
        $this->manager->flush();
    }

    /** @param list<int> $ids */
    public function all(array $ids): void
    {
        foreach ($ids as $id) {
            $this->byId($id);
        }
    }

    public function recursive(int $id): void
    {
        $this->recursive($id - 1);
    }
}

interface Finder
{
    public function lookup(int $id): void;
}

final class DatabaseFinder implements Finder
{
    public function __construct(private CustomerLookup $lookup)
    {
    }

    public function lookup(int $id): void
    {
        $this->lookup->byId($id);
    }
}

final class MemoryFinder implements Finder
{
    public function lookup(int $id): void
    {
    }
}

final class Service
{
    public function __construct(private CustomerLookup $lookup, private Finder $finder)
    {
    }

    /** @param list<int> $ids */
    public function run(array $ids, Customer $customer): void
    {
        $this->lookup->byId(1);
        foreach ($ids as $id) {
            $this->lookup->byId($id);
            $this->finder->lookup($id);
            $this->lookup->save($customer);
            $this->lookup->all($ids);
            $this->lookup->cached($id);
            $this->lookup->recursive($id);
            $this->helper($id);
            self::describe($id);
        }
        array_map(fn (int $id): ?Customer => $this->lookup->byId($id), $ids);
        $unrelated = fn (int $id): ?Customer => $this->lookup->byId($id);
    }

    private function helper(int $id): void
    {
        $this->finder->lookup($id);
    }

    private static function describe(int $id): string
    {
        return (string) $id;
    }
}

final class GuardedService
{
    public function __construct(private EntityManagerInterface $manager, private CustomerLookup $lookup)
    {
    }

    public function save(Customer $customer, bool $flush = true): void
    {
        $this->manager->persist($customer);
        if ($flush) {
            $this->manager->flush();
        }
    }

    public function store(Customer $customer, bool $flush = true): void
    {
        $this->manager->persist($customer);
        if (!$flush) {
            return;
        }
        $this->manager->flush();
    }

    /** @param list<Customer> $customers */
    public function run(array $customers, bool $unknown): void
    {
        foreach ($customers as $customer) {
            $this->save($customer, false);
            $this->store($customer, flush: false);
            $this->save($customer);
            $this->store($customer, $unknown);
            if ($unknown) {
                $this->lookup->byId(1);
            }
        }
        foreach (array_chunk($customers, 100) as $chunk) {
            $this->lookup->byId(1);
            foreach ($chunk as $customer) {
                $this->save($customer);
            }
        }
        $chunks = array_chunk($customers, 100);
        foreach ($chunks as $chunk) {
            $this->lookup->byId(1);
        }
        $this->manager->flush();
    }
}
