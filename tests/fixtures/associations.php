<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

/** @param list<Order|null> $orders */
function associations(array $orders): void
{
    foreach ($orders as $order) {
        $order?->getCustomer();
        $order?->contact;
        if (null === $order) {
            continue;
        }
        $order->getCustomer()->name;
        $order->getCustomers()->toArray();
        $order->contact?->name;
        $order->eagerCustomer->name;
        $order->getEagerCustomer()->name;
        $order->getLabel();
        $order->contact = null;
        $order->id;
        foreach ($orders as $other) {
            $other?->getCustomers()->toArray();
        }
    }
    $order = new Order();
    $order->getCustomer();
    $order->contact;
}
