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

/** Each loop gets its own orders: a later loop over the same array finds the customers already loaded. */
function unsafeUses(\ArrayAccess $arrayAccess): void
{
    foreach (orders() as $order) {
        $order->getCustomer()->getName();
        $order->getCustomer()->getName(); // repeated access in the same iteration is reported once
    }
    foreach (orders() as $order) {
        $customer = $order->getCustomer();
        $customer->getName();
    }
    foreach (orders() as $order) {
        $other = $order->getCustomer();
        $other->getId();
        $other->getName();
    }
    foreach (orders() as $order) {
        inspectCustomer($order->getCustomer());
    }
    foreach (orders() as $order) {
        $order->getCustomer()->getMisleadingIdentifier();
    }
    foreach (orders() as $order) {
        $arrayAccess[] = $order->getCustomer();
    }
    foreach (orders() as $order) {
        $aliasSource = $order->getCustomer();
        $alias = $aliasSource;
        $alias->getName();
    }
    foreach (orders() as $order) {
        $captured = $order->getCustomer();
        $callback = static fn () => $captured->getName();
    }
    foreach (orders() as $order) {
        $capturedInClosure = $order->getCustomer();
        $closure = static function () use ($capturedInClosure): string {
            return $capturedInClosure->getName();
        };
    }
    foreach (orders() as $order) {
        $order->getCustomers()->count();
    }
    foreach (orders() as $order) {
        $other = $order->getCustomer();
        $other->getRegion()->getId();
    }
}

function inspectCustomer(Customer $customer): void
{
    $customer->getName();
}

/** @return list<Order> */
function orders(): array
{
    return [];
}

/** Methods that read only the identifier of the proxy do not load it, however they format it. */
function identifierOnlyGetters(): void
{
    foreach (orders() as $order) {
        $order->getCustomer()->getIdString();
        $order->getCustomer()?->getIdNullsafe();
    }
    foreach (orders() as $order) {
        $order->getCustomer()->getIdWithName();
    }
    foreach (orders() as $order) {
        $order->getCustomer()->getIdThroughGetter(); // limitation: a call on $this is not followed
    }
    foreach (orders() as $order) {
        $order->getCustomer()->getIdOfRegion();
    }
}
