<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @param list<int>              $ids
 * @param Collection<int, Order> $orders
 */
function callbacks(array $ids, Collection $orders, EntityManagerInterface $manager): void
{
    array_map(static fn (int $id): ?object => $manager->find(Customer::class, $id), $ids);
    $orders->map(static fn (Order $order): string => $order->getCustomer()->getName());
    usort($ids, static function (int $a, int $b) use ($manager): int {
        return $manager->find(Customer::class, $a) <=> $manager->find(Customer::class, $b);
    });
    foreach ($ids as $id) {
        array_filter($ids, static fn (int $other): bool => null !== $manager->find(Customer::class, $other));
    }
    $later = static fn (int $id): ?object => $manager->find(Customer::class, $id);
}

/** @param list<Order> $orders */
function invariantReceivers(array $orders, Order $fixed): void
{
    foreach ($orders as $order) {
        $fixed->getCustomer()->getName();
        while (true) {
            $order->getCustomer()->getName();
            break;
        }
        foreach ($order->getCustomers() as $customer) {
            $order->contact?->name;
        }
    }
}

/**
 * @param list<Order> $orders
 *
 * @return list<Customer>
 */
function referencesOnly(array $orders, Customer $expected): array
{
    foreach ($orders as $order) {
        if ($order->getCustomer() === $expected) {
            continue;
        }
    }

    return array_map(static fn (Order $order): Customer => $order->getCustomer(), $orders);
}
