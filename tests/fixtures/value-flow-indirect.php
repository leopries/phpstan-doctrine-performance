<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\ValueFlowIndirect;

use Doctrine\ORM\EntityManagerInterface;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer;

final class Lookup
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    public function byId(int $id): ?Customer
    {
        return $this->manager->find(Customer::class, $id);
    }

    public function touch(int $id): ?Customer
    {
        $customer = $this->manager->find(Customer::class, $id);
        $this->manager->getConnection()->executeStatement('UPDATE customers SET seen = 1');

        return $customer;
    }

    public function save(): void
    {
        $this->manager->flush();
    }
}

final class Service
{
    public function __construct(private Lookup $lookup)
    {
    }

    /** @param list<int> $ids */
    public function run(array $ids, int $default): void
    {
        foreach ($ids as $id) {
            $this->lookup->byId($default);
            $this->lookup->byId($id);
            $this->settings();
            // Writes have their effect in every iteration, whatever the arguments are.
            $this->lookup->touch($default);
            $this->lookup->save();
        }
    }

    private function settings(): ?Customer
    {
        return $this->lookup->byId(1);
    }
}
