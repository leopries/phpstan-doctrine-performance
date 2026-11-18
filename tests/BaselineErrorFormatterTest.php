<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Leopries\PHPStanDoctrinePerformance\BaselineErrorFormatter;
use Leopries\PHPStanDoctrinePerformance\Severity;
use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\DependencyInjection\NeonAdapter;
use PHPStan\Testing\ErrorFormatterTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @coversNothing */
final class BaselineErrorFormatterTest extends ErrorFormatterTestCase
{
    private string $directory;
    private string $file = 'baseline.neon';

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'neon' => ['baseline.neon'];
        yield 'php' => ['baseline.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/doctrine-baseline-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/src', 0o777, true);
        $this->directory = (string) realpath($this->directory);
        touch($this->directory.'/src/A.php');
        touch($this->directory.'/src/B.php');
    }

    private function use(string $file): void
    {
        $this->file = $file;
        putenv(BaselineErrorFormatter::ENV_FILE.'='.$this->path());
    }

    private function path(): string
    {
        return $this->directory.'/'.$this->file;
    }

    protected function tearDown(): void
    {
        putenv(BaselineErrorFormatter::ENV_FILE);
        @unlink($this->directory.'/baseline.php');
        @unlink($this->directory.'/baseline.neon');
        @unlink($this->directory.'/src/A.php');
        @unlink($this->directory.'/src/B.php');
        @rmdir($this->directory.'/src');
        @rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('formats')]
    public function testWritesOnlyDoctrineFindings(string $file): void
    {
        $this->use($file);
        $output = $this->format([], [
            $this->finding('Call to A::save() inside a loop executes a Doctrine query.', 'src/A.php'),
            $this->finding('Call to A::save() inside a loop executes a Doctrine query.', 'src/A.php'),
            $this->finding("It's in B", 'src/B.php', 'doctrine.queryInNestedLoop'),
            new Error('Not a Doctrine finding', $this->directory.'/src/A.php', 1, true, null, null, null, null, null, 'argument.type'),
            new Error('Not ignorable', $this->directory.'/src/A.php', 1, false, null, null, null, null, null, 'doctrine.queryInLoop', [Severity::METADATA_SCORE => 50]),
        ]);

        self::assertStringContainsString('Wrote 3 Doctrine findings to '.$this->path().'.', $output);
        self::assertStringContainsString("includes:\n        - ".$this->path(), $output);
        self::assertSame([
            ['rawMessage' => 'Call to A::save() inside a loop executes a Doctrine query.', 'identifier' => 'doctrine.queryInLoop', 'count' => 2, 'path' => $this->directory.'/src/A.php'],
            ['rawMessage' => "It's in B", 'identifier' => 'doctrine.queryInNestedLoop', 'count' => 1, 'path' => $this->directory.'/src/B.php'],
        ], $this->baseline());
        self::assertStringContainsString('baseline.php' === $file ? "'path' => __DIR__.'/src/A.php'" : 'path: "src/A.php"', (string) file_get_contents($this->path()));
    }

    #[DataProvider('formats')]
    public function testAddsToAnIncludedBaseline(string $file): void
    {
        $this->use($file);
        $this->format([], [$this->finding('Old', 'src/A.php')]);
        $included = $this->baseline();

        // Included, the accepted finding no longer shows up: only the new ones arrive and are added.
        $output = $this->format($included, [$this->finding('New', 'src/A.php'), $this->finding('Old', 'src/B.php')]);

        self::assertStringContainsString('Added 2 Doctrine findings', $output);
        self::assertStringNotContainsString('includes:', $output);
        self::assertSame(['New', 'Old', 'Old'], array_column($this->baseline(), 'rawMessage'));
        self::assertSame([1, 1, 1], array_column($this->baseline(), 'count'));

        self::assertStringContainsString('No new Doctrine findings', $this->format($this->baseline(), []));
    }

    #[DataProvider('formats')]
    public function testRewritesABaselineThatIsNotIncluded(string $file): void
    {
        $this->use($file);
        $this->format([], [$this->finding('Old', 'src/A.php'), $this->finding('Fixed', 'src/A.php')]);

        // Not included, every finding shows up again: adding them would double the counts.
        $this->format([], [$this->finding('Old', 'src/A.php')]);

        self::assertSame([['rawMessage' => 'Old', 'identifier' => 'doctrine.queryInLoop', 'count' => 1, 'path' => $this->directory.'/src/A.php']], $this->baseline());
    }

    #[DataProvider('formats')]
    public function testLeavesAForeignFileAlone(string $file): void
    {
        $this->use($file);
        $foreign = 'baseline.php' === $file ? "<?php return 'something else';\n" : "parameters:\n\tlevel: 5\n";
        file_put_contents($this->path(), $foreign);

        $this->assertSame(1, (new BaselineErrorFormatter([]))->formatErrors(new AnalysisResult([$this->finding('A', 'src/A.php')], [], [], [], [], false, null, true, 0, false, []), $this->getOutput()));
        self::assertSame($foreign, file_get_contents($this->path()));
    }

    public function testDefaultsToNeon(): void
    {
        self::assertSame('phpstan-doctrine-baseline.neon', BaselineErrorFormatter::DEFAULT_FILE);
    }

    public function testNeonKeepsSpecialCharacters(): void
    {
        $this->use('baseline.neon');
        $message = "@at 100% \"quoted\" back\\slash 'single' ünïcode";
        $this->format([], [$this->finding($message, 'src/A.php')]);

        // PHPStan's own loader reads the file; %% and a leading @@ become % and @ when the container is compiled.
        $loaded = new NeonAdapter(['[parameters][ignoreErrors][][path]'])->load($this->path());
        self::assertSame([['rawMessage' => "@@at 100%% \"quoted\" back\\slash 'single' ünïcode", 'identifier' => 'doctrine.queryInLoop', 'count' => 1, 'path' => $this->directory.'/src/A.php']], $loaded['parameters']['ignoreErrors']);

        // Read back while included, the message matches and the file stays as it is.
        self::assertStringContainsString('No new Doctrine findings', $this->format($this->baseline(), []));
        self::assertSame([$message], array_column($this->baseline(), 'rawMessage'));
    }

    /**
     * @param array<mixed> $ignoreErrors
     * @param list<Error>  $errors
     */
    private function format(array $ignoreErrors, array $errors): string
    {
        $before = $this->getOutputContent();
        self::assertSame(0, (new BaselineErrorFormatter($ignoreErrors))->formatErrors(new AnalysisResult($errors, [], [], [], [], false, null, true, 0, false, []), $this->getOutput()));

        return substr($this->getOutputContent(), \strlen($before));
    }

    /** @return mixed the entries as the DI container sees them */
    private function baseline(): mixed
    {
        if ('baseline.php' === $this->file) {
            $data = require $this->path();
            self::assertIsArray($data);

            return $data['parameters']['ignoreErrors'];
        }

        $data = new NeonAdapter(['[parameters][ignoreErrors][][path]'])->load($this->path());
        $unescape = static fn (mixed $value): mixed => \is_string($value) ? preg_replace('/^@@/', '@', str_replace('%%', '%', $value)) : $value;

        return array_map(static fn (array $entry): array => array_map($unescape, $entry), $data['parameters']['ignoreErrors']);
    }

    private function finding(string $message, string $file, string $identifier = 'doctrine.queryInLoop'): Error
    {
        return new Error($message, $this->directory.'/'.$file, 1, true, null, null, null, null, null, $identifier, [Severity::METADATA_SCORE => 50]);
    }
}
