<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Composer\InstalledVersions;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Leopries\PHPStanDoctrinePerformance\DoctrineQueryInLoopRule;

/**
 * @extends RuleTestCase<DoctrineQueryInLoopRule>
 *
 * @coversNothing
 */
final class DoctrineQueryInLoopRuleTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            InstalledVersions::getInstallPath('phpstan/phpstan-doctrine').'/extension.neon',
            __DIR__.'/rules.neon',
        ];
    }

    public function testQueries(): void
    {
        $single = ' executes inside a loop. Potential N+1 queries. Batch queries or fetch the data before the loop.';
        $nested = ' executes inside nested loops. Potential N×M queries (N² when both dimensions grow with N). Batch queries or fetch the data before the loop.';
        // Queries whose receiver and arguments the loop does not change carry the "run it once" tip.
        $this->analyse([__DIR__.'/fixtures/queries.php'], [
            ['Doctrine query find()'.$single, 45],
            ['Doctrine query findOneBy()'.$single, 46],
            // findActive() is written in the project: the indirect rule follows it into its body.
            ['Doctrine query find()'.$single, 48],
            ['Doctrine query getResult()'.$single, 49],
            ['Doctrine query executeQuery()'.$single, 50, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query fetchOne()'.$single, 51, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query executeQuery()'.$single, 52, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query executeQuery()'.$single, 53],
            ['Doctrine query fetchAllAssociative()'.$single, 54],
            ['Doctrine query getArrayResult()'.$single, 55],
            ['Doctrine query update()'.$single, 56],
            ['Doctrine query findAll()'.$single, 66, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query find()'.$nested, 67],
            ['Doctrine query fetchOne()'.$single, 69, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query execute()'.$nested, 70],
            ['Doctrine query fetchOne()'.$single, 73],
            ['Doctrine query executeStatement()'.$single, 77],
            ['Doctrine query fetchOne()'.$single, 78],
            ['Doctrine query executeStatement()'.$single, 79],
            ['Doctrine query fetchOne()'.$single, 79],
            ['Doctrine query find()'.$single, 84],
            ['Doctrine query FIND()'.$single, 85],
            ['Doctrine query flush()'.$single, 86],
        ]);
    }

    public function testCachedQueries(): void
    {
        $single = ' executes inside a loop. Potential N+1 queries. Batch queries or fetch the data before the loop.';
        // Reads served by the result cache or the second-level cache are not queries; everything in uncachedQueries() is.
        $this->analyse([__DIR__.'/fixtures/cached-queries.php'], [
            ['Doctrine query getResult()'.$single, 51],
            ['Doctrine query getResult()'.$single, 52],
            ['Doctrine query getResult()'.$single, 53],
            ['Doctrine query getResult()'.$single, 57],
            ['Doctrine query getResult()'.$single, 62],
            ['Doctrine query getResult()'.$single, 66],
            ['Doctrine query find()'.$single, 68],
            ['Doctrine query executeQuery()'.$single, 69, DoctrineQueryInLoopRule::INDEPENDENT],
        ]);
    }

    public function testAssociations(): void
    {
        $prefix = 'Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$';
        $single = ' accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.';
        $nested = ' accessed inside nested loops. Potential N×M (possibly N²) queries; fetch-join or batch-load the association before the loop.';
        $this->analyse([__DIR__.'/fixtures/associations.php'], [
            [$prefix.'customer'.$single, 14],
            [$prefix.'customers'.$single, 15],
            [$prefix.'contact'.$single, 16],
            [$prefix.'customers'.$nested, 23],
        ]);
    }

    public function testAssociationUses(): void
    {
        $prefix = 'Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$';
        $suffix = ' accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.';
        $this->analyse([__DIR__.'/fixtures/association-usage.php'], [
            [$prefix.'customer'.$suffix, 37],
            [$prefix.'customer'.$suffix, 41],
            [$prefix.'customer'.$suffix, 45],
            [$prefix.'customer'.$suffix, 50],
            [$prefix.'customer'.$suffix, 53],
            [$prefix.'customer'.$suffix, 56],
            [$prefix.'customer'.$suffix, 59],
            [$prefix.'customer'.$suffix, 64],
            [$prefix.'customer'.$suffix, 68],
            [$prefix.'customers'.$suffix, 74],
            [$prefix.'customer'.$suffix, 77],
        ]);
    }

    public function testGlobalAssociationUses(): void
    {
        $this->analyse([__DIR__.'/fixtures/association-global.php'], [
            ['Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$customer accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.', 6],
        ]);
    }

    public function testFetchedAssociations(): void
    {
        $message = 'Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$customer accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.';
        $this->analyse([__DIR__.'/fixtures/fetched-associations.php'], [
            [$message, 95],
            [$message, 98],
            [$message, 101],
            [$message, 104],
            [$message, 107],
            [$message, 111],
            [$message, 116],
            [$message, 126],
            [$message, 137],
            ['Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$contact accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.', 145],
            [$message, 161],
        ]);
    }

    public function testBoundedLoops(): void
    {
        $single = 'Doctrine query find() executes inside a loop. Potential N+1 queries. Batch queries or fetch the data before the loop.';
        $this->analyse([__DIR__.'/fixtures/bounded-loops.php'], [
            ['Doctrine query find() executes inside statically bounded loops (at most 2 iterations in total). Fixed query cost; this is not input-dependent N+1 growth.', 12],
            [$single, 16],
            ['Doctrine query find() executes inside statically bounded loops (at most 6 iterations in total). Fixed query cost; this is not input-dependent N+1 growth.', 21],
            [$single, 25],
        ]);
    }

    public function testIterationCallbacks(): void
    {
        $single = 'Doctrine query find() executes inside a loop. Potential N+1 queries. Batch queries or fetch the data before the loop.';
        $lazy = ' accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.';
        $order = 'Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$';
        $this->analyse([__DIR__.'/fixtures/callbacks.php'], [
            [$single, 14],
            ['Potential lazy Doctrine association Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Order::$customer accessed inside a loop. Potential N+1 queries; fetch-join or batch-load the association before the loop.', 15],
            [$single, 17],
            [$single, 17],
            ['Doctrine query find() executes inside nested loops. Potential N×M queries (N² when both dimensions grow with N). Batch queries or fetch the data before the loop.', 20],
            // An owner that never changes is skipped (29); `while (true)` and loops that keep the owner do not multiply.
            [$order.'customer'.$lazy, 31],
            [$order.'customers'.$lazy, 34],
            [$order.'contact'.$lazy, 35],
        ]);
    }

    public function testValueFlow(): void
    {
        $single = ' executes inside a loop. Potential N+1 queries. Batch queries or fetch the data before the loop.';
        $nested = ' executes inside nested loops. Potential N×M queries (N² when both dimensions grow with N). Batch queries or fetch the data before the loop.';
        $this->analyse([__DIR__.'/fixtures/value-flow.php'], [
            // Nothing the loop changes reaches these: the same query runs in every iteration.
            ['Doctrine query findBy()'.$single, 38, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query findOneBy()'.$single, 39, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query findAll()'.$single, 40, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query getResult()'.$single, 41, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query fetchOne()'.$nested, 43, DoctrineQueryInLoopRule::INDEPENDENT],
            ['Doctrine query findAll()'.$single, 46, DoctrineQueryInLoopRule::INDEPENDENT],
            // Loop variables, counters, changed objects, by-reference functions, callbacks and assigned properties.
            ['Doctrine query find()'.$single, 57],
            ['Doctrine query findBy()'.$single, 58],
            ['Doctrine query findBy()'.$single, 59],
            ['Doctrine query getResult()'.$single, 63],
            ['Doctrine query findBy()'.$single, 64],
            ['Doctrine query find()'.$single, 65],
            ['Doctrine query findBy()'.$single, 66],
            ['Doctrine query findBy()'.$single, 67],
            ['Doctrine query find()'.$single, 69],
            // Flushes and writes are never "the same query again".
            ['Doctrine query flush()'.$single, 70],
            ['Doctrine query executeStatement()'.$single, 71],
            ['Doctrine query findBy()'.$single, 74],
            ['Doctrine query findBy()'.$single, 75, DoctrineQueryInLoopRule::INDEPENDENT], // limitation: the body repeats the condition's query,
        ]);
    }

    protected function getRule(): Rule
    {
        require_once __DIR__.'/fixtures/Entities.php';
        require_once __DIR__.'/fixtures/queries.php';
        require_once __DIR__.'/fixtures/value-flow.php';
        require_once __DIR__.'/fixtures/association-usage.php';
        require_once __DIR__.'/fixtures/fetched-associations.php';

        return self::getContainer()->getByType(DoctrineQueryInLoopRule::class);
    }
}
