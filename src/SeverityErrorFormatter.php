<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/**
 * Groups the Doctrine findings by cause and lists the causes worst first; other PHPStan errors are left out:
 * `phpstan analyse --error-format=doctrinePerformance`, with `-v` for every location.
 * DOCTRINE_REPORT_MIN_LEVEL and DOCTRINE_REPORT_TOP shorten the list, see CauseReport.
 */
final class SeverityErrorFormatter implements ErrorFormatter
{
    private const int LOCATIONS = 3;

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $report = CauseReport::fromAnalysisResult($analysisResult);
        foreach ($report->causes as $group) {
            $locations = $group['locations'];

            $output->writeLineFormatted(\sprintf('%3d %-8s %s', $group['score'], $group['level'], CauseReport::shorten($group['cause'])));
            $output->writeLineFormatted(\sprintf(
                '             %d %s%s', \count($locations), 1 === \count($locations) ? 'loop' : 'loops',
                null === $group['origin'] ? '' : ', query at '.$group['origin'],
            ));
            $output->writeLineFormatted(\sprintf('             %d = %s', $group['score'], $group['breakdown']));
            foreach ($output->isVerbose() ? $locations : \array_slice($locations, 0, self::LOCATIONS) as $location) {
                $output->writeLineFormatted(\sprintf('             %3d %s:%d', $location['score'], $location['file'], $location['line']));
            }
            if (!$output->isVerbose() && \count($locations) > self::LOCATIONS) {
                $output->writeLineFormatted(\sprintf('                 … %d more, -v lists all', \count($locations) - self::LOCATIONS));
            }
        }

        $output->writeLineFormatted('');
        foreach ($report->warnings as $warning) {
            $output->writeLineFormatted($warning);
        }
        $output->writeLineFormatted($report->summary());

        // A report, not a gate: the regular run with its baseline decides whether the build fails.
        return 0;
    }
}
