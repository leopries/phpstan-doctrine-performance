<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Region
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $name;

    public function getId(): int
    {
        return $this->id;
    }
}

#[ORM\Entity]
class Customer
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $name;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    public Region $region;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMisleadingIdentifier(): string
    {
        return $this->name;
    }

    public function getRegion(): Region
    {
        return $this->region;
    }
}

#[ORM\MappedSuperclass]
class OrderAssociations
{
    #[ORM\ManyToOne(targetEntity: Customer::class)]
    protected Customer $customer;

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function setCustomer(Customer $customer): void
    {
        $this->customer = $customer;
    }
}

#[ORM\Entity]
class Order extends OrderAssociations
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    public ?Customer $contact = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, fetch: 'EAGER')]
    public Customer $eagerCustomer;

    /** @var Collection<int, Customer> */
    #[ORM\ManyToMany(targetEntity: Customer::class, fetch: 'EXTRA_LAZY')]
    private Collection $customers;

    public function getCustomers(): Collection
    {
        return $this->customers;
    }

    public function getEagerCustomer(): Customer
    {
        return $this->eagerCustomer;
    }

    public function getLabel(): string
    {
        return 'order';
    }
}
