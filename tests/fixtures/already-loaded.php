<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

/** @param list<Order> $orders */
function loadedByEarlierLoop(array $orders, Customer $customer): void
{
    foreach ($orders as $order) {
        $order->getCustomer()->getName();
    }
    // The same orders: the first loop initialized their customers.
    foreach ($orders as $other) {
        $other->getCustomer()->getName();
    }
    foreach ($orders as $order) {
        $order->contact?->getName();
    }
    $orders[] = new Order();
    // The array changed in between.
    foreach ($orders as $order) {
        $order->getCustomer()->getName();
    }
}

/** @param list<Order> $orders */
function handedOnBetweenLoops(array $orders, \Closure $change): void
{
    foreach ($orders as $order) {
        $order->getCustomer()->getName();
    }
    $change($orders);
    foreach ($orders as $order) {
        $order->getCustomer()->getName();
    }
}

/**
 * Each loop gets its own orders, so that no loop sees what an earlier one loaded.
 *
 * @param list<Order>          $orders
 * @param array<int, Customer> $customers
 */
function assignedBefore(array $orders, Customer $customer, array $customers): void
{
    foreach (loadedOrders() as $order) {
        $fresh = new Order();
        $fresh->getCustomer()->getName();
        $fresh->getCustomers()->toArray();
    }
    foreach (loadedOrders() as $order) {
        $order->setCustomer($customer);
        $order->getCustomer()->getName();
        $order->contact = null;
        $order->contact?->getName();
    }
    foreach (loadedOrders() as $order) {
        $created = new Customer();
        $order->setCustomer($created);
        $order->getCustomer()->getName();
    }
    foreach (loadedOrders() as $order) {
        // A different customer every iteration may be a different proxy every time.
        $order->setCustomer($customers[$order->id]);
        $order->getCustomer()->getName();
    }
    foreach (loadedOrders() as $order) {
        if ($order->id > 0) {
            $order->setCustomer($customer);
        }
        $order->getCustomer()->getName();
    }
    foreach (loadedOrders() as $order) {
        $order->setCustomer($customer);
        $order = $orders[0];
        $order->getCustomer()->getName();
    }
    $order = new Order();
    foreach (loadedOrders() as $order) {
        $order->getCustomer()->getName();
    }
}

/** @return list<Order> */
function loadedOrders(): array
{
    return [];
}
