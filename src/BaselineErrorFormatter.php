<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;

/**
 * Accepts the Doctrine findings of a run: `phpstan analyse --error-format=doctrineBaseline` writes them to their own
 * baseline file, phpstan-doctrine-baseline.neon or DOCTRINE_BASELINE_FILE. Other PHPStan errors are left out. A file
 * ending in .php is written as a PHP baseline, any other as NEON.
 *
 * Once the file is included, accepted findings no longer show up, so the next run adds only new ones to it.
 * While it is not included, every finding shows up and the file is written from scratch, so counts never double.
 *
 * @phpstan-type Entry array{rawMessage: string, identifier: string, count: int, path: string}
 */
final class BaselineErrorFormatter implements ErrorFormatter
{
    public const string ENV_FILE = 'DOCTRINE_BASELINE_FILE';
    public const string DEFAULT_FILE = 'phpstan-doctrine-baseline.neon';
    public const string COMMAND = 'vendor/bin/phpstan analyse --error-format=doctrineBaseline';

    /** @param array<mixed> $ignoreErrors the ignoreErrors parameter of the running configuration */
    public function __construct(private readonly array $ignoreErrors)
    {
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $file = self::file();
        $existing = is_file($file) ? self::read($file) : [];
        if (null === $existing) {
            $output->writeLineFormatted(\sprintf('<error>%s does not return a baseline; not changed.</error>', $file));

            return 1;
        }
        $included = array_any($existing, $this->isConfigured(...));

        $entries = [];
        foreach ($included ? $existing : [] as $entry) {
            $entries[self::key($entry)] = $entry;
        }
        $added = 0;
        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            $identifier = $error->getIdentifier();
            if (!$error->canBeIgnored() || null === $identifier || !\is_int($error->getMetadata()[Severity::METADATA_SCORE] ?? null)) {
                continue;
            }
            $entry = ['rawMessage' => $error->getMessage(), 'identifier' => $identifier, 'count' => 1, 'path' => self::normalize($error->getFilePath())];
            $key = self::key($entry);
            isset($entries[$key]) ? ++$entries[$key]['count'] : $entries[$key] = $entry;
            ++$added;
        }

        if ($included && 0 === $added) {
            $output->writeLineFormatted(\sprintf('No new Doctrine findings; %s is unchanged.', self::display($file)));

            return 0;
        }

        ksort($entries, \SORT_STRING);
        file_put_contents($file, self::export($file, array_values($entries)));
        $output->writeLineFormatted(\sprintf(
            '%s %d Doctrine %s to %s.', $included ? 'Added' : 'Wrote', $added, 1 === $added ? 'finding' : 'findings', self::display($file),
        ));
        if (!$included) {
            $output->writeLineFormatted('');
            $output->writeLineFormatted('Include it once in your PHPStan configuration, unless it already is:');
            $output->writeLineFormatted('');
            $output->writeLineFormatted('    includes:');
            $output->writeLineFormatted('        - '.self::display($file));
        }

        return 0;
    }

    private static function file(): string
    {
        $file = trim((string) getenv(self::ENV_FILE));
        $file = '' === $file ? self::DEFAULT_FILE : $file;
        $cwd = getcwd();

        $file = false === $cwd || self::isAbsolute($file) ? $file : $cwd.'/'.$file;

        return self::normalize(\dirname($file)).'/'.basename($file);
    }

    /** Symlinked directories (/var → /private/var) would otherwise make one file two baseline entries. */
    private static function normalize(string $path): string
    {
        $real = realpath($path);

        return false === $real ? $path : $real;
    }

    private static function display(string $file): string
    {
        $cwd = getcwd();
        $cwd = false === $cwd ? false : self::normalize($cwd);

        return false !== $cwd && str_starts_with($file, $cwd.'/') ? substr($file, \strlen($cwd) + 1) : $file;
    }

    private static function isPhp(string $file): bool
    {
        return str_ends_with(strtolower($file), '.php');
    }

    /** @return list<Entry>|null null when the file is not a baseline */
    private static function read(string $file): ?array
    {
        $errors = self::isPhp($file) ? self::readPhp($file) : self::readNeon($file);
        if (null === $errors) {
            return null;
        }

        $entries = [];
        foreach ($errors as $error) {
            if (\is_array($error) && \is_string($error['rawMessage'] ?? null) && \is_string($error['identifier'] ?? null)
                && \is_int($error['count'] ?? null) && \is_string($error['path'] ?? null)) {
                $entries[] = ['rawMessage' => $error['rawMessage'], 'identifier' => $error['identifier'], 'count' => $error['count'], 'path' => self::normalize($error['path'])];
            }
        }

        return $entries;
    }

    /** @return array<mixed>|null */
    private static function readPhp(string $file): ?array
    {
        $data = (static fn (): mixed => require $file)();
        $errors = \is_array($data) && \is_array($data['parameters'] ?? null) ? ($data['parameters']['ignoreErrors'] ?? []) : null;

        return \is_array($errors) ? $errors : null;
    }

    /**
     * Reads back the NEON this formatter writes; PHPStan's own NEON parser is scoped inside its phar and no API.
     * Anything else, even valid NEON, is not taken for a baseline, so the file is left alone.
     *
     * @return list<array<string, int|string>>|null
     */
    private static function readNeon(string $file): ?array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($file));
        $lines = array_values(array_filter(
            false === $lines ? [] : $lines,
            static fn (string $line): bool => '' !== trim($line) && !str_starts_with(ltrim($line), '#'),
        ));
        if ('parameters:' !== rtrim($lines[0] ?? '') || 1 !== preg_match('/^\s+ignoreErrors:\s*(\[\])?$/', $lines[1] ?? '', $match)) {
            return null;
        }
        if (isset($match[1])) {
            return 2 === \count($lines) ? [] : null;
        }

        $errors = [];
        foreach (\array_slice($lines, 2) as $line) {
            if (1 === preg_match('/^\s+-$/', $line)) {
                $errors[] = [];
                continue;
            }
            if ([] === $errors || 1 !== preg_match('/^\s+(rawMessage|identifier|count|path):\s+("(?:[^"\\\\]|\\\\.)*"|\d+)$/', $line, $match)) {
                return null;
            }
            $value = '"' === $match[2][0] ? json_decode($match[2]) : (int) $match[2];
            if (!\is_string($value) && !\is_int($value)) {
                return null;
            }
            if (\is_string($value)) {
                $value = preg_replace('/^@@/', '@', str_replace('%%', '%', $value)) ?? $value;
            }
            // PHPStan resolves a relative path against the directory of the NEON file that contains it.
            $errors[array_key_last($errors)][$match[1]] = 'path' === $match[1] && \is_string($value) && !self::isAbsolute($value)
                ? \dirname($file).'/'.$value : $value;
        }

        return $errors;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /** @param Entry $entry */
    private function isConfigured(array $entry): bool
    {
        foreach ($this->ignoreErrors as $ignore) {
            if (\is_array($ignore) && ($ignore['rawMessage'] ?? null) === $entry['rawMessage'] && ($ignore['identifier'] ?? null) === $entry['identifier']
                && \is_string($ignore['path'] ?? null) && self::samePath($ignore['path'], $entry['path'])) {
                return true;
            }
        }

        return false;
    }

    private static function samePath(string $a, string $b): bool
    {
        return $a === $b || self::normalize($a) === $b;
    }

    /** @param Entry $entry */
    private static function key(array $entry): string
    {
        return $entry['path']."\0".$entry['identifier']."\0".$entry['rawMessage'];
    }

    /** @param list<Entry> $entries */
    private static function export(string $file, array $entries): string
    {
        return self::isPhp($file) ? self::exportPhp($entries, \dirname($file)) : self::exportNeon($entries, \dirname($file));
    }

    /** @param list<Entry> $entries */
    private static function exportNeon(array $entries, string $directory): string
    {
        $neon = '# Accepted Doctrine performance findings, written by: '.self::COMMAND."\n"
            ."# PHPStan reports entries that no longer match; delete those, or the whole list to start over.\n\n"
            ."parameters:\n\tignoreErrors:".([] === $entries ? " []\n" : "\n");
        foreach ($entries as $entry) {
            $path = str_starts_with($entry['path'], $directory.'/') ? substr($entry['path'], \strlen($directory) + 1) : $entry['path'];
            $neon .= \sprintf(
                "\t\t-\n\t\t\trawMessage: %s\n\t\t\tidentifier: %s\n\t\t\tcount: %d\n\t\t\tpath: %s\n",
                self::neonString($entry['rawMessage']), self::neonString($entry['identifier']), $entry['count'], self::neonString($path),
            );
        }

        return $neon;
    }

    /** A double-quoted NEON string, escaped like JSON; `%` and a leading `@` are doubled for the DI container. */
    private static function neonString(string $value): string
    {
        return json_encode(preg_replace('#^@|%#', '$0$0', $value), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /** @param list<Entry> $entries */
    private static function exportPhp(array $entries, string $directory): string
    {
        $php = "<?php declare(strict_types=1);\n\n"
            .'// Accepted Doctrine performance findings, written by: '.self::COMMAND."\n"
            ."// PHPStan reports entries that no longer match; delete those, or the whole list to start over.\n\n"
            ."return ['parameters' => ['ignoreErrors' => [\n";
        foreach ($entries as $entry) {
            $path = str_starts_with($entry['path'], $directory.'/')
                ? '__DIR__.'.var_export(substr($entry['path'], \strlen($directory)), true)
                : var_export($entry['path'], true);
            $php .= \sprintf(
                "    [\n        'rawMessage' => %s,\n        'identifier' => %s,\n        'count' => %d,\n        'path' => %s,\n    ],\n",
                var_export($entry['rawMessage'], true), var_export($entry['identifier'], true), $entry['count'], $path,
            );
        }

        return $php."]]];\n";
    }
}
