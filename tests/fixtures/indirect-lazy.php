<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\IndirectLazy;

use Doctrine\ORM\EntityManagerInterface;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order;

final class CustomerMapper
{
    public function map(Customer $customer): string
    {
        return $customer->getRegion()->name;
    }
}

final class OrderMapper
{
    public function __construct(private CustomerMapper $customers)
    {
    }

    public function map(Order $order): string
    {
        return $order->getCustomer()->getName();
    }

    public function forward(Order $order): string
    {
        return $this->map($order);
    }

    public function nested(Order $order): string
    {
        return $this->customers->map($order->getCustomer());
    }

    public function chained(Order $order): string
    {
        return $order->getCustomer()->getRegion()->name;
    }

    public function reference(Order $order): Customer
    {
        return $order->getCustomer();
    }

    public function identifier(Order $order): int
    {
        $customer = $order->getCustomer();

        return $customer->getId();
    }

    public function eager(Order $order): string
    {
        return $order->getEagerCustomer()->getName();
    }

    public function recursive(Order $order, int $depth): int
    {
        if ($depth > 0) {
            return $this->recursive($order, $depth - 1);
        }

        return $order->getCustomers()->count();
    }

    public function cached(Order $order): string
    {
        return $order->getCustomer()->getName();
    }

    public static function label(string $prefix, Order $order): string
    {
        return $prefix.$order->contact?->name;
    }
}

final class Row
{
    public string $name;

    public function __construct(Order $order)
    {
        $this->name = $order->getCustomer()->getName();
    }

    public static function fromOrder(Order $order): self
    {
        return new self($order);
    }
}

interface Formatter
{
    public function format(Order $order): string;
}

final class CustomerFormatter implements Formatter
{
    public function format(Order $order): string
    {
        return $order->getCustomer()->getName();
    }
}

final class PlainFormatter implements Formatter
{
    public function format(Order $order): string
    {
        return $order->getLabel();
    }
}

final class Service
{
    public function __construct(private OrderMapper $mapper, private CustomerMapper $customers, private Formatter $formatter, private EntityManagerInterface $manager)
    {
    }

    /** @param list<Order> $orders */
    public function run(array $orders, Order $fixed): void
    {
        $this->mapper->map($fixed);
        foreach ($orders as $order) {
            $this->mapper->map($order);
            $this->mapper->forward($order);
            $this->mapper->nested($order);
            $this->mapper->chained($order);
            $this->mapper->reference($order);
            $this->mapper->identifier($order);
            $this->mapper->eager($order);
            $this->mapper->map($fixed);
            $this->mapper->recursive($order, 3);
            $this->mapper->cached($order);
            OrderMapper::label(order: $order, prefix: 'x');
            Row::fromOrder($order);
            $this->formatter->format($order);
        }
        foreach ($orders as $order) {
            $this->customers->map($order->getCustomer());
            if ($order->contact instanceof Customer) {
                $this->customers->map($order->contact);
            }
        }
        array_map(fn (Order $order): string => $this->mapper->map($order), $orders);
        $later = fn (Order $order): string => $this->mapper->map($order);
        foreach ($orders as $order) {
            foreach ($orders as $other) {
                $this->mapper->map($order);
                $this->mapper->map($other);
            }
        }
        foreach (array_chunk($orders, 100) as $chunk) {
            $this->mapper->map($chunk[0]);
        }
    }

    public function fetched(): void
    {
        foreach ($this->joined() as $order) {
            self::label($order);
        }
        // Only the nested association is missing from the fetch join.
        foreach ($this->joined() as $other) {
            $this->mapper->chained($other);
        }
    }

    /** @param list<Order> $orders */
    public function alreadyRead(array $orders): void
    {
        // The direct rule reports the access on 176; the call loads nothing more for the customer, only its region.
        foreach ($orders as $order) {
            $order->getCustomer()->getName();
            $this->mapper->map($order);
            $this->mapper->chained($order);
        }
    }

    /** The variants that run() calls after map() in the same iteration, each on entities of its own. */
    public function separateLoops(): void
    {
        foreach ($this->orders() as $order) {
            $this->mapper->forward($order);
        }
        foreach ($this->orders() as $order) {
            $this->mapper->chained($order);
        }
        foreach ($this->orders() as $order) {
            Row::fromOrder($order);
        }
        foreach ($this->orders() as $order) {
            $this->formatter->format($order);
        }
        foreach ($this->orders() as $order) {
            $this->customers->map($order->getCustomer());
        }
        foreach ($this->orders() as $order) {
            foreach ($this->orders() as $other) {
                $this->mapper->map($order);
            }
        }
    }

    /** @param list<Order> $orders */
    public function loadedByEarlierLoop(array $orders): void
    {
        foreach ($orders as $order) {
            $this->mapper->map($order);
        }
        foreach ($orders as $order) {
            $this->mapper->map($order);
        }
        $orders[] = new Order();
        foreach ($orders as $order) {
            $this->mapper->map($order);
        }
    }

    public function assignedBefore(Customer $customer): void
    {
        foreach ($this->orders() as $order) {
            $fresh = new Order();
            $this->mapper->map($fresh);
        }
        foreach ($this->orders() as $order) {
            $this->mapper->map(new Order());
        }
        foreach ($this->orders() as $order) {
            $order->setCustomer($customer);
            $this->mapper->map($order);
        }
        foreach ($this->orders() as $order) {
            $order->setCustomer($this->manager->getReference(Customer::class, $order->id));
            $this->mapper->map($order);
        }
    }

    /** @return list<Order> */
    private function orders(): array
    {
        return [];
    }

    /** @return list<Order> */
    private function joined(): array
    {
        return $this->manager->createQueryBuilder()->select('o', 'c')
            ->from(Order::class, 'o')->leftJoin('o.customer', 'c')->getQuery()->getResult();
    }

    private static function label(Order $order): string
    {
        return $order->getCustomer()->getName();
    }
}
