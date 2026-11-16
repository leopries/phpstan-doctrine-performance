<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;

final class OrderLoader
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    /** @return list<Order> */
    public function fetched(): array
    {
        return $this->manager->createQueryBuilder()->select('o', 'c')
            ->from(Order::class, 'o')->leftJoin('o.customer', 'c')->getQuery()->getResult();
    }

    /** @return list<Order> */
    public function joinedOnly(): array
    {
        return $this->manager->createQueryBuilder()->select('o')
            ->from(Order::class, 'o')->leftJoin('o.customer', 'c')->getQuery()->getResult();
    }

    /** @return list<Order> */
    public function otherAssociation(): array
    {
        return $this->manager->createQueryBuilder()->select('o', 'c')
            ->from(Order::class, 'o')->leftJoin('o.contact', 'c')->getQuery()->getResult();
    }

    /** @return list<Order> */
    public function selectedThenReset(): array
    {
        return $this->manager->createQueryBuilder()->select('o', 'c')
            ->from(Order::class, 'o')->leftJoin('o.customer', 'c')->select('o')->getQuery()->getResult();
    }

    /** @return list<Order> */
    public function conditionalSelection(bool $fetch): array
    {
        $qb = $this->manager->createQueryBuilder()->select('o')->from(Order::class, 'o')->leftJoin('o.customer', 'c');
        if ($fetch) {
            $qb->addSelect('c');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Order> */
    public function mixedReturns(bool $fetch): array
    {
        if ($fetch) {
            return $this->fetched();
        }

        return $this->joinedOnly();
    }

    /** @return list<Order> */
    public function fetchedCollection(): array
    {
        $qb = $this->manager->createQueryBuilder();
        $qb->select('o, c')->from(Order::class, 'o')->leftJoin('o.customers', 'c');

        return $qb->getQuery()->getResult();
    }
}

final class FetchedAssociationConsumer
{
    public function __construct(private OrderLoader $loader, private EntityManagerInterface $manager)
    {
    }

    public function safeReads(): void
    {
        $orders = $this->loader->fetched();
        foreach ($orders as $order) {
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->fetchedCollection() as $order) {
            $order->getCustomers()->toArray();
        }
        $this->inspectFetched($this->loader->fetched());
        $groups = $this->loadGroups([1, 2]);
        $this->inspectGroups($groups);
    }

    /** @param array<int, Customer> $replacement */
    public function unsafeReads(bool $fetch, array $replacement): void
    {
        foreach ($this->loader->joinedOnly() as $order) {
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->otherAssociation() as $order) {
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->selectedThenReset() as $order) {
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->conditionalSelection($fetch) as $order) {
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->mixedReturns($fetch) as $order) {
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->fetched() as $order) {
            $order->setCustomer($replacement[$order->id]);
            $order->getCustomer()->getName();
        }
        foreach ($this->loader->fetched() as $order) {
            $alias = $order;
            $alias->setCustomer($replacement[$order->id]);
            $order->getCustomer()->getName();
        }
        $this->inspectMixed($this->loader->fetched());
        $this->inspectMixed($this->loader->joinedOnly());
    }

    /** @param list<Order> $orders */
    public function publicInput(array $orders): void
    {
        foreach ($orders as $order) {
            $order->getCustomer()->getName();
        }
    }

    /** @param list<Order> $orders */
    public function conditionalInput(array $orders, bool $fetch): void
    {
        if ($fetch) {
            $orders = $this->loader->fetched();
        }
        foreach ($orders as $order) {
            $order->getCustomer()->getName();
        }
    }

    /** @param array<int, Customer> $replacement */
    public function propertyReplacement(array $replacement): void
    {
        foreach ($this->loader->otherAssociation() as $order) {
            $order->contact = $replacement[$order->id];
            $order->contact?->getName();
        }
    }

    /** @param list<Order> $orders */
    private function inspectFetched(array $orders): void
    {
        foreach ($orders as $order) {
            $order->getCustomer()->getName();
        }
    }

    /** @param list<Order> $orders */
    private function inspectMixed(array $orders): void
    {
        foreach ($orders as $order) {
            $order->getCustomer()->getName();
        }
    }

    /** @param list<int> $ids
     * @return array<int, list<Order>>
     */
    private function loadGroups(array $ids): array
    {
        $groups = [];
        foreach ($ids as $id) {
            $groups[$id] = $this->loader->fetchedCollection();
        }

        return $groups;
    }

    /** @param array<int, list<Order>> $groups */
    private function inspectGroups(array $groups): void
    {
        foreach ($groups as $orders) {
            foreach ($orders as $order) {
                $order->getCustomers()->toArray();
            }
        }
    }
}

final class NestedFetchedAssociationConsumer
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    /** @param list<int> $indices */
    public function readNested(array $indices): void
    {
        $orders = $this->manager->createQueryBuilder()->select('o', 'c', 'r')
            ->from(Order::class, 'o')->join('o.customer', 'c')->join('c.region', 'r')
            ->getQuery()->getResult();
        foreach ($indices as $index) {
            $orders[$index]->getCustomer()->getName();
            $orders[$index]->getCustomer()->getRegion()->name;
        }
    }
}
