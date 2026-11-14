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
            // 126 is outside the loop.
            [$call('OrderMapper::map'), 127, $tip($customer.' in OrderMapper::map()')],
            [$call('OrderMapper::forward'), 128, $tip($customer.' via OrderMapper::forward → OrderMapper::map')],
            [$call('OrderMapper::nested'), 129, $tip($customer.' in OrderMapper::nested(), '.$region.' via OrderMapper::nested → CustomerMapper::map')],
            [$call('OrderMapper::chained'), 130, $tip($customer.' in OrderMapper::chained(), '.$region.' in OrderMapper::chained()')],
            // reference() and identifier() never initialize the proxy, eager() reads an eager mapping, 134 passes a loop-independent order.
            [$call('OrderMapper::recursive'), 135, $tip('Order::$customers (lazy to-many) in OrderMapper::recursive()', 'low (35/100) = 45 one collection load per owner, never served from the identity map')],
            // cached() on 136 is an ignored method.
            [$call('OrderMapper::label'), 137, $tip('Order::$contact (lazy to-one) in OrderMapper::label()')],
            [$call('Row::fromOrder'), 138, $tip($customer.' via Row::fromOrder → Row::__construct')],
            [$call('Formatter::format'), 139, $tip($customer.' in CustomerFormatter::format()', 'info (10/100) = '.$toOne.' − 10 depends on which implementation is called')],
            [$call('CustomerMapper::map'), 142, $tip($region.' in CustomerMapper::map()')],
            [$call('CustomerMapper::map'), 144, $tip($region.' in CustomerMapper::map()', 'info (10/100) = '.$toOne.' − 10 behind a condition, not on every iteration')],
            [$call('OrderMapper::map'), 147, $tip($customer.' in OrderMapper::map()')],
            // The closure on 148 is not iterated; on 151 only the outer loop changes the order.
            [$call('OrderMapper::map'), 151, $tip($customer.' in OrderMapper::map()')],
            [$call('OrderMapper::map', true), 152, $tip($customer.' in OrderMapper::map()', 'medium (45/100) = '.$toOne.' + 25 nested loops multiply the count', true)],
            // The loop over array_chunk() on 155 is a batch loop; 163 passes orders whose customer is fetch-joined.
            [$call('OrderMapper::chained'), 167, $tip($region.' in OrderMapper::chained()')],
            // 176 reads the customer in the loop itself, which the direct rule reports: only the region is left for the call on 178.
            [$call('OrderMapper::chained'), 178, $tip($region.' in OrderMapper::chained()')],
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
