<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/**
 * The report of SeverityErrorFormatter as a Markdown summary for a pull request or a pipeline summary:
 * `phpstan analyse --error-format=doctrinePerformanceMarkdown`. A summary has to stay short, so it lists the
 * worst 20 causes unless DOCTRINE_REPORT_TOP says otherwise.
 */
final class SeverityMarkdownErrorFormatter implements ErrorFormatter
{
    private const int TOP = 20;

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $report = CauseReport::fromAnalysisResult($analysisResult, self::TOP);
        $lines = ['## Doctrine N+1 report', '', \sprintf('**%d causes** in %d loops.', $report->totalCauses, $report->totalLoops), ''];
        $lines[] = '| '.implode(' | ', array_map(ucfirst(...), array_keys($report->levels))).' |';
        $lines[] = '|'.str_repeat(' ---: |', \count($report->levels));
        $lines[] = '| '.implode(' | ', $report->levels).' |';
        foreach ($report->warnings as $warning) {
            array_push($lines, '', '> '.self::escape($warning));
        }

        if ([] !== $report->causes) {
            array_push($lines, '', '| Score | Level | Cause | Loops | Worst location |', '| ---: | --- | --- | ---: | --- |');
        }
        foreach ($report->causes as $group) {
            $worst = $group['locations'][0];
            $lines[] = \sprintf(
                '| %d | %s | %s | %d | %s |', $group['score'], $group['level'], self::code(CauseReport::shorten($group['cause'])),
                \count($group['locations']), self::code($worst['file'].':'.$worst['line']),
            );
        }
        if ($report->hidden() > 0) {
            array_push($lines, '', \sprintf(
                '%d of %d causes shown, %d hidden (%s). `composer lint:doctrine` lists all of them.', \count($report->causes), $report->totalCauses, $report->hidden(),
                implode(', ', array_filter([null === $report->minLevel ? null : 'level '.$report->minLevel.' and above', null === $report->top ? null : 'top '.$report->top])),
            ));
        }
        $output->writeRaw(implode("\n", $lines)."\n");

        // A report, not a gate: the regular run with its baseline decides whether the build fails.
        return 0;
    }

    /** A pipe ends a table cell even inside a code span, and a backtick would end the span. */
    private static function code(string $text): string
    {
        return '`'.str_replace(['`', '|'], ["'", '\|'], $text).'`';
    }

    private static function escape(string $text): string
    {
        return str_replace(['|', '<'], ['\|', '&lt;'], $text);
    }
}
