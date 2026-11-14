<?php declare(strict_types=1);

use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order;

foreach ([new Order()] as $order) {
    $customer = $order->getCustomer();
    $customer->getName();
    $order->getCustomer()->getId();
}
