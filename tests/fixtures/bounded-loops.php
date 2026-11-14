<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;

/** @param list<int> $ids */
function boundedLoops(EntityManagerInterface $manager, array $ids): void
{
    $types = [Customer::class, Order::class];
    foreach ($types as $type) {
        $manager->find($type, 1);
    }
    foreach ($ids as $id) {
        foreach ($types as $type) {
            $manager->find($type, $id);
        }
    }
    foreach ([1, 2] as $id) {
        foreach ([1, 2, 3] as $other) {
            $manager->find(Customer::class, $id + $other);
        }
    }
    foreach ($types as &$type) {
        $manager->find($type, 1);
    }
}
