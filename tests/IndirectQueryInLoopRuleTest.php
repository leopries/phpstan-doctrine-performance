<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Composer\InstalledVersions;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Leopries\PHPStanDoctrinePerformance\CallGraphCollector;
use Leopries\PHPStanDoctrinePerformance\ClassHierarchyCollector;
use Leopries\PHPStanDoctrinePerformance\IndirectQueryInLoopRule;

/**
 * @extends RuleTestCase<IndirectQueryInLoopRule>
 *
 * @coversNothing
 */
final class IndirectQueryInLoopRuleTest extends RuleTestCase
{
    private const string INDEPENDENT = '; receiver and arguments of the call do not depend on the loop, so every iteration runs the same query: call it once before the loop and reuse the result, unless the loop changes the data it reads.';

    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            InstalledVersions::getInstallPath('phpstan/phpstan-doctrine').'/extension.neon',
            __DIR__.'/indirect.neon',
        ];
    }

    public function testIndirectQueries(): void
    {
        $call = static fn (string $method, bool $nested = false): string => \sprintf(
            'Call to %s() inside %s executes a Doctrine query. Batch the work or fetch the data before the loop.', $method, $nested ? 'nested loops' : 'a loop',
        );
        $query = 'Severity low (30/100) = 30 find() by identifier, served from the identity map for repeated identifiers.';
        $independent = self::INDEPENDENT;
        $flush = 'Runs flush(); potential N+1 queries. Severity high (60/100) = 60 flush per iteration recomputes the whole unit of work.';
        $this->analyse([__DIR__.'/fixtures/indirect.php'], [
            [$call('CustomerLookup::byId'), 34, 'Runs find(); potential N+1 queries. '.$query],
            [$call('CustomerLookup::byId'), 79, 'Runs find(); potential N+1 queries. '.$query],
            [$call('DatabaseFinder::lookup'), 80, 'Runs find() via CustomerLookup::byId; potential N+1 queries. '.$query],
            [$call('CustomerLookup::save'), 81, $flush],
            // all($ids) gets the whole list in every iteration, so it does not depend on the loop.
            [$call('CustomerLookup::all'), 82, 'Runs find() via CustomerLookup::byId'.$independent.' Severity medium (45/100) = 30 find() by identifier, served from the identity map for repeated identifiers + 25 nested loops multiply the count − 10 same query every iteration, can run once before the loop.'],
            [$call('Service::helper'), 85, 'Runs find() via DatabaseFinder::lookup → CustomerLookup::byId; potential N+1 queries. '.$query],
            [$call('CustomerLookup::byId'), 88, 'Runs find(); potential N+1 queries. '.$query],
            // save($customer, false) and store($customer, flush: false) on 130 and 131 never reach the flush.
            [$call('GuardedService::save'), 132, $flush],
            [$call('GuardedService::store'), 133, $flush],
            [$call('CustomerLookup::byId'), 135, 'Runs find()'.$independent.' Severity info (10/100) = 30 find() by identifier, served from the identity map for repeated identifiers − 10 behind a condition, not on every iteration − 10 same query every iteration, can run once before the loop.'],
            // Loops over array_chunk() are batch loops (139, 146); the loop over one chunk still counts.
            [$call('GuardedService::save'), 141, $flush],
        ]);
    }

    public function testCachedQueries(): void
    {
        // readOnlyByTeam() and findByTeamCached() read through the result cache and the second-level cache.
        $this->analyse([__DIR__.'/fixtures/cached-indirect.php'], [
            ['Call to HistoryRepository::byTeam() inside a loop executes a Doctrine query. Batch the work or fetch the data before the loop.', 50, 'Runs getResult(); potential N+1 queries. Severity medium (50/100) = 50 one query per iteration.'],
        ]);
    }

    public function testValueFlow(): void
    {
        $message = static fn (string $method): string => \sprintf('Call to %s() inside a loop executes a Doctrine query. Batch the work or fetch the data before the loop.', $method);
        $independent = self::INDEPENDENT.' Severity low (20/100) = 30 find() by identifier, served from the identity map for repeated identifiers − 10 same query every iteration, can run once before the loop.';
        $this->analyse([__DIR__.'/fixtures/value-flow-indirect.php'], [
            [$message('Lookup::byId'), 43, 'Runs find()'.$independent],
            [$message('Lookup::byId'), 44, 'Runs find(); potential N+1 queries. Severity low (30/100) = 30 find() by identifier, served from the identity map for repeated identifiers.'],
            [$message('Service::settings'), 45, 'Runs find() via Lookup::byId'.$independent],
            // touch() reads and writes; the read represents it, the write keeps it an N+1.
            // The write outranks the find() in the same method.
            [$message('Lookup::touch'), 47, 'Runs executeStatement(); potential N+1 queries. Severity medium (45/100) = 45 one write statement per iteration.'],
            [$message('Lookup::save'), 48, 'Runs flush(); potential N+1 queries. Severity high (60/100) = 60 flush per iteration recomputes the whole unit of work.'],
        ]);
    }

    protected function getRule(): Rule
    {
        require_once __DIR__.'/fixtures/Entities.php';
        require_once __DIR__.'/fixtures/indirect.php';
        require_once __DIR__.'/fixtures/value-flow-indirect.php';
        require_once __DIR__.'/fixtures/cached-indirect.php';

        return self::getContainer()->getByType(IndirectQueryInLoopRule::class);
    }

    protected function getCollectors(): array
    {
        return [
            self::getContainer()->getByType(CallGraphCollector::class),
            self::getContainer()->getByType(ClassHierarchyCollector::class),
        ];
    }
}
