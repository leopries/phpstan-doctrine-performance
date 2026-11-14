<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

/** @param list<Order> $orders */
function safeReferenceUses(array $orders): array
{
    $customers = [];
    foreach ($orders as $order) {
        $order->getCustomer()->getId();
        $order->getCustomer()->id;
        $order->contact?->getId();
        $order->contact?->id;
        if (null !== $order->contact) {
            $customers[] = $order->contact;
        }
        $customer = $order->getCustomer();
        $customers[$customer->getId()] = $order;
        $anotherCustomer = $order->getCustomer();
        $anotherCustomer->id;
        $reference = $order->getCustomer();
        $customers[] = $reference;
        $customers[] = $order->getCustomer();
        $order->getCustomer();
        $order->getCustomers();
        $order->getCustomer() instanceof Customer;
        $order->getCustomer()->getName(...);
    }

    return $customers;
}

/** @param list<Order> $orders */
function unsafeUses(array $orders, \ArrayAccess $arrayAccess): void
{
    foreach ($orders as $order) {
        $order->getCustomer()->getName();
        $order->getCustomer()->getName(); // repeated access in the same iteration is reported once
    }
    foreach ($orders as $order) {
        $customer = $order->getCustomer();
        $customer->getName();
    }
    foreach ($orders as $order) {
        $other = $order->getCustomer();
        $other->getId();
        $other->getName();
    }
    foreach ($orders as $order) {
        inspectCustomer($order->getCustomer());
    }
    foreach ($orders as $order) {
        $order->getCustomer()->getMisleadingIdentifier();
    }
    foreach ($orders as $order) {
        $arrayAccess[] = $order->getCustomer();
    }
    foreach ($orders as $order) {
        $aliasSource = $order->getCustomer();
        $alias = $aliasSource;
        $alias->getName();
    }
    foreach ($orders as $order) {
        $captured = $order->getCustomer();
        $callback = static fn () => $captured->getName();
    }
    foreach ($orders as $order) {
        $capturedInClosure = $order->getCustomer();
        $closure = static function () use ($capturedInClosure): string {
            return $capturedInClosure->getName();
        };
    }
    foreach ($orders as $order) {
        $order->getCustomers()->count();
    }
    foreach ($orders as $order) {
        $other = $order->getCustomer();
        $other->getRegion()->getId();
    }
}

function inspectCustomer(Customer $customer): void
{
    $customer->getName();
}
