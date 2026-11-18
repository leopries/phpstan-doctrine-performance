<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Composer\InstalledVersions;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use Leopries\PHPStanDoctrinePerformance\QueryCalls;
use Leopries\PHPStanDoctrinePerformance\Severity;

/** @coversNothing */
final class SeverityTest extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            InstalledVersions::getInstallPath('phpstan/phpstan-doctrine').'/extension.neon',
        ];
    }

    public function testScores(): void
    {
        require_once __DIR__.'/fixtures/Entities.php';
        $severity = new Severity(
            self::getContainer()->getByType(ObjectMetadataResolver::class),
            'info', true, __DIR__.'/fixtures/table-stats.json', 200, 100000,
            ['#/Controller/#'], ['#/tests/#'], false,
        );

        self::assertSame(['score' => 50, 'level' => 'medium', 'reasons' => [[50, 'one query per iteration']], 'test' => false], $severity->assess(Severity::QUERY, 1, '/app/src/Service/A.php'));
        self::assertSame(85, $severity->assess(Severity::QUERY, 2, '/app/src/Controller/A.php')['score']);
        self::assertSame('critical', $severity->assess(Severity::FLUSH, 2, '/app/src/Service/A.php')['level']);
        self::assertTrue($severity->assess(Severity::QUERY, 1, '/app/tests/ATest.php')['test']);

        // The identity map caps to-one loads at the 12 regions; 2.5M customers make every miss more likely.
        self::assertSame(['score' => 5, 'level' => 'info', 'reasons' => [[30, 'to-one load, served from the identity map for repeated targets'], [-25, 'small table (~12 rows)']], 'test' => false], $severity->assess(Severity::TO_ONE, 1, '/app/src/A.php', 'Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Region'));
        self::assertSame(['score' => 40, 'level' => 'medium', 'reasons' => [[30, 'to-one load, served from the identity map for repeated targets'], [10, 'large table (~2.5M rows)']], 'test' => false], $severity->assess(Severity::TO_ONE, 1, '/app/src/A.php', 'Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer'));
        // find() by identifier hits the identity map like a to-one load; findOneBy() always queries.
        self::assertSame(['score' => 5, 'level' => 'info', 'reasons' => [[30, 'find() by identifier, served from the identity map for repeated identifiers'], [-25, 'small table (~12 rows)']], 'test' => false], $severity->assess(Severity::FIND, 1, '/app/src/A.php', 'Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Region'));
        self::assertSame(40, $severity->assess(Severity::kind(QueryCalls::READ), 1, '/app/src/A.php', 'Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Region')['score']);
        self::assertSame(Severity::FIND, Severity::kind(QueryCalls::FIND));
        self::assertSame(55, $severity->assess(Severity::TO_MANY, 1, '/app/src/A.php', 'Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer')['score']);
        self::assertSame(40, $severity->assess(Severity::QUERY, 1, '/app/src/A.php', null, false, true)['score']);
        self::assertSame([[50, 'one query per iteration'], [25, 'nested loops multiply the count'], [-10, Severity::LOOP_INDEPENDENT]], $severity->assess(Severity::QUERY, 2, '/app/src/A.php', loopIndependent: true)['reasons']);
    }
}
