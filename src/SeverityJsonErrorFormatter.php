<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/**
 * The report of SeverityErrorFormatter as JSON, for CI artifacts and tooling:
 * `phpstan analyse --error-format=doctrinePerformanceJson`. Causes keep their namespaces and all locations.
 */
final class SeverityJsonErrorFormatter implements ErrorFormatter
{
    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $report = CauseReport::fromAnalysisResult($analysisResult);
        $output->writeRaw(json_encode([
            'totals' => ['causes' => $report->totalCauses, 'loops' => $report->totalLoops, 'levels' => $report->levels],
            'filter' => ['identifiers' => $report->identifiers, 'minLevel' => $report->minLevel, 'top' => $report->top],
            'excluded' => $report->excluded,
            'shown' => \count($report->causes),
            'hidden' => $report->hidden(),
            'warnings' => $report->warnings,
            'causes' => array_map(static fn (array $group): array => [...$group, 'loops' => \count($group['locations'])], $report->causes),
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n");

        // A report, not a gate: the regular run with its baseline decides whether the build fails.
        return 0;
    }
}
