<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;

/**
 * Scores a finding from 0 to 100. The score ranks findings against each other; it is not a query count.
 *
 * @phpstan-type Assessment array{score: int, level: string, reasons: list<array{int, string}>, test: bool}
 */
final class Severity
{
    public const string METADATA_SCORE = 'doctrinePerformanceScore';
    public const string METADATA_LEVEL = 'doctrinePerformanceLevel';
    public const string METADATA_CAUSE = 'doctrinePerformanceCause';
    public const string METADATA_ORIGIN = 'doctrinePerformanceOrigin';
    public const string METADATA_BREAKDOWN = 'doctrinePerformanceBreakdown';
    public const array LEVELS = ['critical' => 80, 'high' => 60, 'medium' => 40, 'low' => 20, 'info' => 0];

    public const string QUERY = 'query';
    public const string WRITE = 'write';
    public const string FLUSH = 'flush';
    public const string TO_MANY = 'toMany';
    public const string TO_ONE = 'toOne';
    public const string BOUNDED = 'bounded';
    public const string LOOP_INDEPENDENT = 'same query every iteration, can run once before the loop';

    /** @var array<string, array{int, string}> */
    private const array BASE = [
        self::FLUSH => [60, 'flush per iteration recomputes the whole unit of work'],
        self::QUERY => [50, 'one query per iteration'],
        self::WRITE => [45, 'one write statement per iteration'],
        self::TO_MANY => [45, 'one collection load per owner, never served from the identity map'],
        self::TO_ONE => [30, 'to-one load, served from the identity map for repeated targets'],
        self::BOUNDED => [10, 'fixed number of iterations'],
    ];

    /** @var array<string, int>|null */
    private ?array $rows = null;

    /**
     * @param list<string> $hotPaths
     * @param list<string> $testPaths
     */
    public function __construct(
        private readonly ObjectMetadataResolver $metadataResolver,
        private readonly string $minimumSeverity,
        private readonly bool $severityTip,
        private readonly ?string $tableStatsFile,
        private readonly int $smallTableRows,
        private readonly int $largeTableRows,
        private readonly array $hotPaths,
        private readonly array $testPaths,
        private readonly bool $reportTests,
    ) {
        if (!isset(self::LEVELS[$minimumSeverity])) {
            throw new \InvalidArgumentException(\sprintf('doctrinePerformance.minimumSeverity must be one of %s, got "%s".', implode(', ', array_keys(self::LEVELS)), $minimumSeverity));
        }
    }

    /** @return Assessment */
    public function assess(string $kind, int $depth, string $file, ?string $entity = null, bool $uncertain = false, bool $conditional = false, bool $loopIndependent = false): array
    {
        $reasons = [self::BASE[$kind] ?? self::BASE[self::QUERY]];

        if ($depth > 1) {
            $reasons[] = [25, 'nested loops multiply the count'];
        }

        $rows = null === $entity ? null : $this->rows($entity);
        if (null !== $rows && self::BOUNDED !== $kind) {
            if ($rows <= $this->smallTableRows) {
                // Identity map lookups cap to-one loads and find() at the number of rows; other queries still run every time.
                $reasons[] = [self::TO_ONE === $kind ? -25 : -10, \sprintf('small table (~%s rows)', self::formatRows($rows))];
            } elseif ($rows >= $this->largeTableRows) {
                $reasons[] = [10, \sprintf('large table (~%s rows)', self::formatRows($rows))];
            }
        }

        $test = $this->matches($this->testPaths, $file);
        if ($test) {
            $reasons[] = [-40, 'test code'];
        } elseif ($this->matches($this->hotPaths, $file)) {
            $reasons[] = [10, 'request path'];
        }

        if ($uncertain) {
            $reasons[] = [-10, 'depends on which implementation is called'];
        }
        if ($conditional) {
            $reasons[] = [-10, 'behind a condition, not on every iteration'];
        }
        if ($loopIndependent) {
            // While it is there it costs as many round trips as an N+1, but the fix is moving one line, and repeated
            // find() calls for the same row are answered by the identity map: rank it just below a real N+1 of the same shape.
            $reasons[] = [-10, self::LOOP_INDEPENDENT];
        }

        return self::scored($reasons, $test);
    }

    /**
     * Adds a reason only one rule knows about to an assessment.
     *
     * @param Assessment $assessment
     *
     * @return Assessment
     */
    public static function adjust(array $assessment, int $points, string $reason): array
    {
        return self::scored([...$assessment['reasons'], [$points, $reason]], $assessment['test']);
    }

    /**
     * @param RuleErrorBuilder<IdentifierRuleError> $builder
     * @param Assessment                            $assessment
     * @param string                                $cause      what has to change to fix the finding; findings sharing it are one problem
     * @param string|null                           $origin     file:line of the query when it is not the reported line
     * @param string|null                           $detail     shown before the severity; kept out of the message so baselines survive refactorings
     */
    public function apply(RuleErrorBuilder $builder, array $assessment, string $cause, ?string $origin = null, ?string $detail = null): ?IdentifierRuleError
    {
        if ($assessment['score'] < self::LEVELS[$this->minimumSeverity] || ($assessment['test'] && !$this->reportTests)) {
            return null;
        }

        // 50 one query per iteration + 25 nested loops − 10 behind a condition
        $breakdown = '';
        foreach ($assessment['reasons'] as [$points, $reason]) {
            $breakdown .= ('' === $breakdown ? '' : ($points < 0 ? ' − ' : ' + ')).abs($points).' '.$reason;
        }

        $builder->metadata([
            self::METADATA_SCORE => $assessment['score'], self::METADATA_LEVEL => $assessment['level'],
            self::METADATA_CAUSE => $cause, self::METADATA_ORIGIN => $origin, self::METADATA_BREAKDOWN => $breakdown,
        ]);
        $tips = null === $detail ? [] : [$detail];
        if ($this->severityTip) {
            $tips[] = \sprintf('Severity %s (%d/100) = %s.', $assessment['level'], $assessment['score'], $breakdown);
        }
        if ([] !== $tips) {
            $builder->tip(implode(' ', $tips));
        }

        return $builder->build();
    }

    /**
     * How bad one query of this kind is next to another, before the location is known.
     *
     * @param QueryCalls::* $queryKind
     */
    public static function weight(string $queryKind, bool $inLoop, bool $conditional, bool $uncertain): int
    {
        return self::BASE[self::kind($queryKind)][0] + ($inLoop ? 25 : 0) - ($conditional ? 10 : 0) - ($uncertain ? 10 : 0);
    }

    /**
     * @param QueryCalls::* $queryKind
     *
     * @return self::FLUSH|self::WRITE|self::QUERY
     */
    public static function kind(string $queryKind): string
    {
        return match ($queryKind) {
            QueryCalls::FLUSH => self::FLUSH,
            QueryCalls::WRITE => self::WRITE,
            default => self::QUERY,
        };
    }

    public static function level(int $score): string
    {
        foreach (self::LEVELS as $level => $minimum) {
            if ($score >= $minimum) {
                return $level;
            }
        }

        return 'info';
    }

    /**
     * @param list<array{int, string}> $reasons
     *
     * @return Assessment
     */
    private static function scored(array $reasons, bool $test): array
    {
        $score = max(0, min(100, array_sum(array_column($reasons, 0))));

        return ['score' => $score, 'level' => self::level($score), 'reasons' => $reasons, 'test' => $test];
    }

    private function rows(string $entity): ?int
    {
        if (null === $this->tableStatsFile || !class_exists($entity)) {
            return null;
        }

        if (null === $this->rows) {
            $this->rows = [];
            $content = is_file($this->tableStatsFile) ? file_get_contents($this->tableStatsFile) : false;
            if (false === $content) {
                throw new \InvalidArgumentException(\sprintf('doctrinePerformance.tableStatsFile "%s" is not readable.', $this->tableStatsFile));
            }
            $data = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
            foreach (\is_array($data) && \is_array($data['tables'] ?? null) ? $data['tables'] : [] as $table => $count) {
                if (\is_string($table) && \is_int($count)) {
                    $this->rows[strtolower($table)] = $count;
                }
            }
        }

        $table = $this->metadataResolver->getClassMetadata($entity)?->getTableName();

        return null === $table ? null : ($this->rows[strtolower($table)] ?? null);
    }

    /** @param list<string> $patterns */
    private function matches(array $patterns, string $file): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    private static function formatRows(int $rows): string
    {
        return match (true) {
            $rows >= 1_000_000 => round($rows / 1_000_000, 1).'M',
            $rows >= 1_000 => round($rows / 1_000).'k',
            default => (string) $rows,
        };
    }
}
