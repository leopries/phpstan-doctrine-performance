<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PHPStan\Command\AnalysisResult;

/**
 * The Doctrine findings of one run grouped by cause, worst first; shared by the report formatters.
 *
 * Error formatters cannot receive command line options, so the filter comes from the environment:
 * DOCTRINE_REPORT_IDENTIFIER=lazyAssociationInLoop keeps only the findings with that identifier (several separated
 * by commas), DOCTRINE_REPORT_MIN_LEVEL=high hides causes below that level, DOCTRINE_REPORT_TOP=20 keeps the worst 20.
 *
 * @internal
 *
 * @phpstan-type Location array{file: string, line: int, score: int}
 * @phpstan-type Cause array{cause: string, score: int, level: string, breakdown: string, origin: string|null, identifiers: list<string>, locations: non-empty-list<Location>}
 */
final readonly class CauseReport
{
    public const string ENV_MIN_LEVEL = 'DOCTRINE_REPORT_MIN_LEVEL';
    public const string ENV_TOP = 'DOCTRINE_REPORT_TOP';
    public const string ENV_IDENTIFIER = 'DOCTRINE_REPORT_IDENTIFIER';
    /** Every identifier the rules report. */
    public const array IDENTIFIERS = [
        'doctrine.queryInLoop', 'doctrine.queryInNestedLoop', 'doctrine.queryInBoundedLoop', 'doctrine.indirectQueryInLoop',
        'doctrine.lazyAssociationInLoop', 'doctrine.indirectLazyAssociationInLoop',
    ];

    /**
     * @param list<Cause>       $causes      the causes left by the filter
     * @param array<string, int> $levels      number of causes per level, before the level and top filters
     * @param list<string>       $warnings    invalid filter values; they are ignored rather than hiding everything
     * @param list<string>|null  $identifiers the identifiers the findings were restricted to; totals count only those
     * @param int                $excluded    findings left out because of their identifier
     */
    private function __construct(
        public array $causes,
        public array $levels,
        public int $totalCauses,
        public int $totalLoops,
        public ?string $minLevel,
        public ?int $top,
        public array $warnings,
        public ?array $identifiers = null,
        public int $excluded = 0,
    ) {
    }

    /** @param int|null $defaultTop limit used when DOCTRINE_REPORT_TOP is not set; DOCTRINE_REPORT_TOP=0 lifts it */
    public static function fromAnalysisResult(AnalysisResult $analysisResult, ?int $defaultTop = null): self
    {
        $cwd = getcwd();
        $relative = static fn (string $path): string => false !== $cwd && str_starts_with($path, $cwd.'/') ? substr($path, \strlen($cwd) + 1) : $path;

        $warnings = [];
        $identifiers = self::identifiers($warnings);
        $excluded = 0;
        $causes = [];
        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            $metadata = $error->getMetadata();
            $score = $metadata[Severity::METADATA_SCORE] ?? null;
            $cause = $metadata[Severity::METADATA_CAUSE] ?? null;
            if (!\is_int($score) || !\is_string($cause)) {
                continue;
            }
            // Filtered per finding, not per cause: a cause reached directly and through calls shows only the asked-for loops.
            if (null !== $identifiers && !\in_array($error->getIdentifier(), $identifiers, true)) {
                ++$excluded;
                continue;
            }

            $origin = $metadata[Severity::METADATA_ORIGIN] ?? null;
            $breakdown = $metadata[Severity::METADATA_BREAKDOWN] ?? null;
            $causes[$cause] ??= ['cause' => $cause, 'score' => -1, 'level' => '', 'breakdown' => '', 'origin' => \is_string($origin) ? $relative($origin) : null, 'identifiers' => [], 'locations' => []];
            if ($score > $causes[$cause]['score']) {
                $causes[$cause]['score'] = $score;
                $causes[$cause]['level'] = Severity::level($score);
                $causes[$cause]['breakdown'] = \is_string($breakdown) ? $breakdown : '';
            }
            if (null !== $error->getIdentifier() && !\in_array($error->getIdentifier(), $causes[$cause]['identifiers'], true)) {
                $causes[$cause]['identifiers'][] = $error->getIdentifier();
            }
            $causes[$cause]['locations'][] = ['file' => $relative($error->getTraitFilePath() ?? $error->getFilePath()), 'line' => $error->getLine() ?? 0, 'score' => $score];
        }

        // Every tie is broken, so two runs over the same code give the same report and CI artifacts can be compared.
        $causes = array_values($causes);
        usort($causes, static fn (array $a, array $b): int => [$b['score'], \count($b['locations']), $a['cause']] <=> [$a['score'], \count($a['locations']), $b['cause']]);
        $levels = array_fill_keys(array_keys(Severity::LEVELS), 0);
        $totalLoops = 0;
        foreach ($causes as $index => $group) {
            sort($causes[$index]['identifiers']);
            usort($causes[$index]['locations'], static fn (array $a, array $b): int => [$b['score'], $a['file'], $a['line']] <=> [$a['score'], $b['file'], $b['line']]);
            ++$levels[$group['level']];
            $totalLoops += \count($group['locations']);
        }

        $minLevel = self::minLevel($warnings);
        $top = self::top($warnings) ?? $defaultTop;
        $shown = $causes;
        if (null !== $minLevel) {
            $shown = array_values(array_filter($shown, static fn (array $group): bool => $group['score'] >= Severity::LEVELS[$minLevel]));
        }
        if (null !== $top && $top > 0) {
            $shown = \array_slice($shown, 0, $top);
        }

        return new self($shown, $levels, \count($causes), $totalLoops, $minLevel, null !== $top && $top > 0 ? $top : null, $warnings, $identifiers, $excluded);
    }

    public function hidden(): int
    {
        return $this->totalCauses - \count($this->causes);
    }

    /** 281 causes in 912 loops: 4 critical, 31 high, …; 261 hidden (DOCTRINE_REPORT_TOP=20). */
    public function summary(): string
    {
        $summary = \sprintf(
            '%d %s in %d %s: %s', $this->totalCauses, 1 === $this->totalCauses ? 'cause' : 'causes', $this->totalLoops, 1 === $this->totalLoops ? 'loop' : 'loops',
            implode(', ', array_map(static fn (string $level, int $count): string => $count.' '.$level, array_keys($this->levels), $this->levels)),
        );
        if (null !== $this->identifiers) {
            $summary .= \sprintf(' (only %s; %d other %s left out)', implode(', ', $this->identifiers), $this->excluded, 1 === $this->excluded ? 'finding' : 'findings');
        }
        if (0 === $this->hidden()) {
            return $summary;
        }

        $filter = array_filter([null === $this->minLevel ? null : self::ENV_MIN_LEVEL.'='.$this->minLevel, null === $this->top ? null : self::ENV_TOP.'='.$this->top]);

        return \sprintf('%s; showing %d, %d hidden (%s)', $summary, \count($this->causes), $this->hidden(), implode(', ', $filter));
    }

    /** Drops namespaces: App\Repository\InvoiceRepository::save → flush() becomes InvoiceRepository::save → flush(). */
    public static function shorten(string $cause): string
    {
        return (string) preg_replace('#(?:[\w]+\\\)+#', '', $cause);
    }

    /**
     * The identifiers to keep, with or without the `doctrine.` prefix and in any case. One unknown identifier ignores
     * the whole filter: a typo must not make the report look clean.
     *
     * @param list<string> $warnings
     *
     * @return list<string>|null
     */
    private static function identifiers(array &$warnings): ?array
    {
        $value = trim((string) getenv(self::ENV_IDENTIFIER));
        if ('' === $value) {
            return null;
        }

        $known = [];
        foreach (self::IDENTIFIERS as $identifier) {
            $known[strtolower($identifier)] = $identifier;
            $known[strtolower(substr($identifier, \strlen('doctrine.')))] = $identifier;
        }
        $identifiers = [];
        foreach (explode(',', $value) as $part) {
            $part = strtolower(trim($part));
            if ('' === $part) {
                continue;
            }
            if (!isset($known[$part])) {
                $warnings[] = \sprintf('Ignoring %s="%s": expected one or more of %s, separated by commas.', self::ENV_IDENTIFIER, $value, implode(', ', self::IDENTIFIERS));

                return null;
            }
            $identifiers[$known[$part]] = $known[$part];
        }

        return [] === $identifiers ? null : array_values($identifiers);
    }

    /** @param list<string> $warnings */
    private static function minLevel(array &$warnings): ?string
    {
        $value = strtolower(trim((string) getenv(self::ENV_MIN_LEVEL)));
        if ('' === $value || isset(Severity::LEVELS[$value])) {
            return '' === $value ? null : $value;
        }
        $warnings[] = \sprintf('Ignoring %s="%s": expected one of %s.', self::ENV_MIN_LEVEL, $value, implode(', ', array_keys(Severity::LEVELS)));

        return null;
    }

    /** @param list<string> $warnings */
    private static function top(array &$warnings): ?int
    {
        $value = trim((string) getenv(self::ENV_TOP));
        if ('' === $value || (ctype_digit($value) && \strlen($value) < 10)) {
            return '' === $value ? null : (int) $value;
        }
        $warnings[] = \sprintf('Ignoring %s="%s": expected a number, 0 for no limit.', self::ENV_TOP, $value);

        return null;
    }
}
