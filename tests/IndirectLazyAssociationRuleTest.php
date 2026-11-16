<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Composer\InstalledVersions;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Leopries\PHPStanDoctrinePerformance\ClassHierarchyCollector;
use Leopries\PHPStanDoctrinePerformance\EntityArgumentCollector;
use Leopries\PHPStanDoctrinePerformance\IndirectLazyAssociationRule;
use Leopries\PHPStanDoctrinePerformance\ParameterAssociationCollector;

/**
 * @extends RuleTestCase<IndirectLazyAssociationRule>
 *
 * @coversNothing
 */
final class IndirectLazyAssociationRuleTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            InstalledVersions::getInstallPath('phpstan/phpstan-doctrine').'/extension.neon',
            __DIR__.'/indirect-lazy.neon',
        ];
    }

    public function testIndirectLazyAssociations(): void
    {
        $call = static fn (string $method, bool $nested = false): string => \sprintf(
            'Call to %s() inside %s lazily loads Doctrine associations of its argument. Fetch-join or batch-load them before the loop.', $method, $nested ? 'nested loops' : 'a loop',
        );
        $tip = static fn (string $reads, string $severity = 'low (20/100) = 30 to-one load, served from the identity map for repeated targets', bool $nested = false): string => \sprintf(
            'Reads %s; potential %s queries unless the argument is already fetch-joined. Severity %s − 10 fetch state of the argument is not known across the call.', $reads, $nested ? 'N×M (possibly N²)' : 'N+1', $severity,
        );
        $toOne = '30 to-one load, served from the identity map for repeated targets';
        $customer = 'Order::$customer (lazy to-one)';
        $region = 'Customer::$region (lazy to-one)';
        $this->analyse([__DIR__.'/fixtures/indirect-lazy.php'], [
            // 126 is outside the loop. map() on 127 loads the customer: the later calls of the same iteration only add what it left.
            [$call('OrderMapper::map'), 127, $tip($customer.' in OrderMapper::map()')],
            [$call('OrderMapper::nested'), 129, $tip($region.' via OrderMapper::nested → CustomerMapper::map')],
            // 128, 130, 138 and 139 load nothing new; reference() and identifier() never initialize the proxy, eager() reads an eager
            // mapping, 134 passes a loop-independent order.
            [$call('OrderMapper::recursive'), 135, $tip('Order::$customers (lazy to-many) in OrderMapper::recursive()', 'low (35/100) = 45 one collection load per owner, never served from the identity map')],
            // cached() on 136 is an ignored method.
            [$call('OrderMapper::label'), 137, $tip('Order::$contact (lazy to-one) in OrderMapper::label()')],
            // The loop on 141 iterates the same orders: their customers and regions are loaded. Only the contact is new.
            [$call('CustomerMapper::map'), 144, $tip($region.' in CustomerMapper::map()', 'info (10/100) = '.$toOne.' − 10 behind a condition, not on every iteration')],
            [$call('OrderMapper::map'), 147, $tip($customer.' in OrderMapper::map()')],
            // The closure on 148 is not iterated; the outer loop on 149 iterates the same orders again, the inner one may not.
            [$call('OrderMapper::map', true), 152, $tip($customer.' in OrderMapper::map()', 'medium (45/100) = '.$toOne.' + 25 nested loops multiply the count', true)],
            // The loop over array_chunk() on 155 is a batch loop; 163 passes orders whose customer is fetch-joined.
            [$call('OrderMapper::chained'), 167, $tip($region.' in OrderMapper::chained()')],
            // 176 reads the customer in the loop itself, which the direct rule reports: only the region is left for the call on 178.
            [$call('OrderMapper::chained'), 178, $tip($region.' in OrderMapper::chained()')],
            // The calls of 128 to 151, each on entities of its own.
            [$call('OrderMapper::forward'), 186, $tip($customer.' via OrderMapper::forward → OrderMapper::map')],
            [$call('OrderMapper::chained'), 189, $tip($customer.' in OrderMapper::chained(), '.$region.' in OrderMapper::chained()')],
            [$call('Row::fromOrder'), 192, $tip($customer.' via Row::fromOrder → Row::__construct')],
            [$call('Formatter::format'), 195, $tip($customer.' in CustomerFormatter::format()', 'info (10/100) = '.$toOne.' − 10 depends on which implementation is called')],
            [$call('CustomerMapper::map'), 198, $tip($region.' in CustomerMapper::map()')],
            [$call('OrderMapper::map'), 202, $tip($customer.' in OrderMapper::map()')],
            // 214 iterates the orders of 210 again; 216 adds one in between.
            [$call('OrderMapper::map'), 211, $tip($customer.' in OrderMapper::map()')],
            [$call('OrderMapper::map'), 218, $tip($customer.' in OrderMapper::map()')],
            // New orders (226, 229) and a customer that stays the same (233) are no proxies; getReference() on 236 may be.
            [$call('OrderMapper::map'), 237, $tip($customer.' in OrderMapper::map()')],
        ]);
    }

    protected function getRule(): Rule
    {
        require_once __DIR__.'/fixtures/Entities.php';
        require_once __DIR__.'/fixtures/indirect-lazy.php';

        return self::getContainer()->getByType(IndirectLazyAssociationRule::class);
    }

    protected function getCollectors(): array
    {
        return [
            self::getContainer()->getByType(ParameterAssociationCollector::class),
            self::getContainer()->getByType(EntityArgumentCollector::class),
            self::getContainer()->getByType(ClassHierarchyCollector::class),
        ];
    }
}
