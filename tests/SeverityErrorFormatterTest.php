<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Testing\ErrorFormatterTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Leopries\PHPStanDoctrinePerformance\CauseReport;
use Leopries\PHPStanDoctrinePerformance\Severity;
use Leopries\PHPStanDoctrinePerformance\SeverityErrorFormatter;
use Leopries\PHPStanDoctrinePerformance\SeverityJsonErrorFormatter;
use Leopries\PHPStanDoctrinePerformance\SeverityMarkdownErrorFormatter;

/** @coversNothing */
final class SeverityErrorFormatterTest extends ErrorFormatterTestCase
{
    private const string SAVE = 'App\Repository\InvoiceRepository::save → flush()';
    private const string FIND = 'App\Repository\TagRepository::findOneByName';
    private const string LAZY = 'App\Entity\Invoice::$tags';

    protected function tearDown(): void
    {
        putenv(CauseReport::ENV_MIN_LEVEL);
        putenv(CauseReport::ENV_TOP);
        parent::tearDown();
    }

    public function testGroupsByCauseWorstFirst(): void
    {
        $report = CauseReport::fromAnalysisResult(self::findings());

        // FIND and LAZY share the score; the cause with more loops comes first.
        self::assertSame([self::SAVE, self::LAZY, self::FIND], array_column($report->causes, 'cause'));
        self::assertSame([85, 50, 50], array_column($report->causes, 'score'));
        self::assertSame(['critical', 'medium', 'medium'], array_column($report->causes, 'level'));
        self::assertSame(['critical' => 1, 'high' => 0, 'medium' => 2, 'low' => 0, 'info' => 0], $report->levels);
        self::assertSame([3, 6, 0], [$report->totalCauses, $report->totalLoops, $report->hidden()]);

        // The worst loop decides score and breakdown; locations are relative and sorted by score, file and line.
        $save = $report->causes[0];
        self::assertSame('60 flush + 25 nested loops', $save['breakdown']);
        self::assertSame('src/Repository/InvoiceRepository.php:40', $save['origin']);
        self::assertSame(['doctrine.indirectQueryInLoop', 'doctrine.queryInNestedLoop'], $save['identifiers']);
        self::assertSame([
            ['file' => 'src/Service/B.php', 'line' => 7, 'score' => 85],
            ['file' => 'src/Service/A.php', 'line' => 9, 'score' => 60],
            ['file' => 'src/Service/A.php', 'line' => 30, 'score' => 60],
        ], $save['locations']);
        self::assertNull($report->causes[1]['origin']);
        self::assertSame('3 causes in 6 loops: 1 critical, 0 high, 2 medium, 0 low, 0 info', $report->summary());
    }

    public function testTextReport(): void
    {
        $text = $this->format(new SeverityErrorFormatter());

        self::assertStringContainsString(" 85 critical InvoiceRepository::save → flush()\n             3 loops, query at src/Repository/InvoiceRepository.php:40\n             85 = 60 flush + 25 nested loops\n              85 src/Service/B.php:7\n", $text);
        self::assertStringEndsWith("\n\n3 causes in 6 loops: 1 critical, 0 high, 2 medium, 0 low, 0 info\n", $text);
    }

    public function testLevelFilter(): void
    {
        putenv(CauseReport::ENV_MIN_LEVEL.'= High ');
        $report = CauseReport::fromAnalysisResult(self::findings());

        self::assertSame([self::SAVE], array_column($report->causes, 'cause'));
        self::assertSame([3, 6, 2, 'high'], [$report->totalCauses, $report->totalLoops, $report->hidden(), $report->minLevel]);

        $text = $this->format(new SeverityErrorFormatter());
        self::assertStringNotContainsString('TagRepository', $text);
        self::assertStringNotContainsString('Invoice::$tags', $text);
        self::assertStringEndsWith("3 causes in 6 loops: 1 critical, 0 high, 2 medium, 0 low, 0 info; showing 1, 2 hidden (DOCTRINE_REPORT_MIN_LEVEL=high)\n", $text);
    }

    public function testTop(): void
    {
        putenv(CauseReport::ENV_TOP.'=2');
        $report = CauseReport::fromAnalysisResult(self::findings());
        self::assertSame([self::SAVE, self::LAZY], array_column($report->causes, 'cause'));
        self::assertStringEndsWith('; showing 2, 1 hidden (DOCTRINE_REPORT_TOP=2)', $report->summary());

        // Both filters: the level first, then the limit.
        putenv(CauseReport::ENV_MIN_LEVEL.'=medium');
        putenv(CauseReport::ENV_TOP.'=1');
        self::assertStringEndsWith('; showing 1, 2 hidden (DOCTRINE_REPORT_MIN_LEVEL=medium, DOCTRINE_REPORT_TOP=1)', CauseReport::fromAnalysisResult(self::findings())->summary());

        // 0 lifts the limit, also the default one of a formatter.
        putenv(CauseReport::ENV_MIN_LEVEL);
        putenv(CauseReport::ENV_TOP.'=0');
        $report = CauseReport::fromAnalysisResult(self::findings(), 1);
        self::assertSame([3, null, []], [\count($report->causes), $report->top, $report->warnings]);
    }

    #[DataProvider('provideInvalidValuesAreIgnoredWithAWarningCases')]
    public function testInvalidValuesAreIgnoredWithAWarning(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $report = CauseReport::fromAnalysisResult(self::findings());

        // Showing everything is the safe side: a typo must not make the report look clean.
        self::assertCount(3, $report->causes);
        self::assertSame([null, null], [$report->minLevel, $report->top]);
        self::assertCount(1, $report->warnings);
        self::assertStringContainsString(\sprintf('Ignoring %s="%s"', $name, $value), $report->warnings[0]);
        self::assertStringContainsString($report->warnings[0]."\n3 causes in 6 loops", $this->format(new SeverityErrorFormatter()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideInvalidValuesAreIgnoredWithAWarningCases(): iterable
    {
        yield 'unknown level' => [CauseReport::ENV_MIN_LEVEL, 'urgent'];
        yield 'score instead of level' => [CauseReport::ENV_MIN_LEVEL, '60'];
        yield 'not a number' => [CauseReport::ENV_TOP, 'ten'];
        yield 'negative' => [CauseReport::ENV_TOP, '-5'];
        yield 'fraction' => [CauseReport::ENV_TOP, '2.5'];
        yield 'overflow' => [CauseReport::ENV_TOP, '99999999999999999999'];
    }

    public function testJson(): void
    {
        putenv(CauseReport::ENV_TOP.'=1');
        $json = $this->format(new SeverityJsonErrorFormatter());

        self::assertSame([
            'totals' => ['causes' => 3, 'loops' => 6, 'levels' => ['critical' => 1, 'high' => 0, 'medium' => 2, 'low' => 0, 'info' => 0]],
            'filter' => ['minLevel' => null, 'top' => 1],
            'shown' => 1,
            'hidden' => 2,
            'warnings' => [],
            'causes' => [[
                'cause' => self::SAVE,
                'score' => 85,
                'level' => 'critical',
                'breakdown' => '60 flush + 25 nested loops',
                'origin' => 'src/Repository/InvoiceRepository.php:40',
                'identifiers' => ['doctrine.indirectQueryInLoop', 'doctrine.queryInNestedLoop'],
                'locations' => [
                    ['file' => 'src/Service/B.php', 'line' => 7, 'score' => 85],
                    ['file' => 'src/Service/A.php', 'line' => 9, 'score' => 60],
                    ['file' => 'src/Service/A.php', 'line' => 30, 'score' => 60],
                ],
                'loops' => 3,
            ]],
        ], json_decode($json, true, 16, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('"src/Service/B.php"', $json);
        self::assertStringContainsString('save → flush()', $json);
    }

    public function testJsonWithoutFindings(): void
    {
        $data = json_decode($this->format(new SeverityJsonErrorFormatter(), new AnalysisResult([new Error('Other error', '/app/A.php', 1)], [], [], [], [], false, null, true, 0, false, [])), true, 16, \JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertSame([[], 0, 0], [$data['causes'], $data['hidden'], $data['totals']['causes'] ?? null]);
    }

    public function testMarkdown(): void
    {
        putenv(CauseReport::ENV_TOP.'=2');

        self::assertSame(<<<'MARKDOWN'
            ## Doctrine N+1 report

            **3 causes** in 6 loops.

            | Critical | High | Medium | Low | Info |
            | ---: | ---: | ---: | ---: | ---: |
            | 1 | 0 | 2 | 0 | 0 |

            | Score | Level | Cause | Loops | Worst location |
            | ---: | --- | --- | ---: | --- |
            | 85 | critical | `InvoiceRepository::save → flush()` | 3 | `src/Service/B.php:7` |
            | 50 | medium | `Invoice::$tags` | 2 | `src/Service/A.php:20` |

            2 of 3 causes shown, 1 hidden (top 2). `composer lint:doctrine` lists all of them.

            MARKDOWN, $this->format(new SeverityMarkdownErrorFormatter()));
    }

    public function testMarkdownShowsWarningsAndEscapesTableCells(): void
    {
        putenv(CauseReport::ENV_MIN_LEVEL.'=a|b');
        $markdown = $this->format(new SeverityMarkdownErrorFormatter(), new AnalysisResult([self::error('A|B::find`x`', 50, 'src/A.php', 1)], [], [], [], [], false, null, true, 0, false, []));

        self::assertStringContainsString('> Ignoring DOCTRINE_REPORT_MIN_LEVEL="a\|b": expected one of critical, high, medium, low, info.', $markdown);
        self::assertStringContainsString("| `A\\|B::find'x'` | 1 |", $markdown);
        self::assertStringNotContainsString('hidden', $markdown);
    }

    private function format(ErrorFormatter $formatter, ?AnalysisResult $result = null): string
    {
        // Every report returns 0: the regular run with its baseline is the gate.
        self::assertSame(0, $formatter->formatErrors($result ?? self::findings(), $this->getOutput()));

        return $this->getOutputContent();
    }

    private static function findings(): AnalysisResult
    {
        return new AnalysisResult([
            self::error(self::LAZY, 50, 'src/Service/A.php', 20, 'doctrine.lazyAssociationInLoop'),
            self::error(self::SAVE, 60, 'src/Service/A.php', 30, 'doctrine.indirectQueryInLoop', '60 flush', 'src/Repository/InvoiceRepository.php:40'),
            self::error(self::SAVE, 60, 'src/Service/A.php', 9, 'doctrine.indirectQueryInLoop', '60 flush', 'src/Repository/InvoiceRepository.php:40'),
            self::error(self::FIND, 50, 'src/Service/A.php', 12),
            self::error(self::SAVE, 85, 'src/Service/B.php', 7, 'doctrine.queryInNestedLoop', '60 flush + 25 nested loops', 'src/Repository/InvoiceRepository.php:40'),
            self::error(self::LAZY, 30, 'src/Service/C.php', 3, 'doctrine.lazyAssociationInLoop'),
            new Error('Not a Doctrine finding', getcwd().'/src/Service/A.php', 1, true, null, null, null, null, null, 'argument.type'),
            new Error('Unusable metadata', getcwd().'/src/Service/A.php', 2, true, null, null, null, null, null, null, [Severity::METADATA_SCORE => '50', Severity::METADATA_CAUSE => self::FIND]),
        ], ['Not file specific'], [], [], [], false, null, true, 0, false, []);
    }

    private static function error(string $cause, int $score, string $file, int $line, string $identifier = 'doctrine.queryInLoop', string $breakdown = 'points', ?string $origin = null): Error
    {
        return new Error('Message', getcwd().'/'.$file, $line, true, null, null, null, null, null, $identifier, [
            Severity::METADATA_SCORE => $score, Severity::METADATA_LEVEL => Severity::level($score), Severity::METADATA_CAUSE => $cause,
            Severity::METADATA_ORIGIN => null === $origin ? null : getcwd().'/'.$origin, Severity::METADATA_BREAKDOWN => $breakdown, 'someFutureKey' => ['x'],
        ]);
    }
}
