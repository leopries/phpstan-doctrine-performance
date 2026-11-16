<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Leopries\PHPStanDoctrinePerformance\BaselineErrorFormatter;
use Leopries\PHPStanDoctrinePerformance\Severity;
use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Testing\ErrorFormatterTestCase;

/** @coversNothing */
final class BaselineErrorFormatterTest extends ErrorFormatterTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/doctrine-baseline-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/src', 0o777, true);
        $this->directory = (string) realpath($this->directory);
        touch($this->directory.'/src/A.php');
        touch($this->directory.'/src/B.php');
        putenv(BaselineErrorFormatter::ENV_FILE.'='.$this->directory.'/baseline.php');
    }

    protected function tearDown(): void
    {
        putenv(BaselineErrorFormatter::ENV_FILE);
        @unlink($this->directory.'/baseline.php');
        @unlink($this->directory.'/src/A.php');
        @unlink($this->directory.'/src/B.php');
        @rmdir($this->directory.'/src');
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testWritesOnlyDoctrineFindings(): void
    {
        $output = $this->format([], [
            $this->finding('Call to A::save() inside a loop executes a Doctrine query.', 'src/A.php'),
            $this->finding('Call to A::save() inside a loop executes a Doctrine query.', 'src/A.php'),
            $this->finding("It's in B", 'src/B.php', 'doctrine.queryInNestedLoop'),
            new Error('Not a Doctrine finding', $this->directory.'/src/A.php', 1, true, null, null, null, null, null, 'argument.type'),
            new Error('Not ignorable', $this->directory.'/src/A.php', 1, false, null, null, null, null, null, 'doctrine.queryInLoop', [Severity::METADATA_SCORE => 50]),
        ]);

        self::assertStringContainsString('Wrote 3 Doctrine findings to '.$this->directory.'/baseline.php.', $output);
        self::assertStringContainsString("includes:\n        - ".$this->directory.'/baseline.php', $output);
        self::assertSame([
            ['rawMessage' => 'Call to A::save() inside a loop executes a Doctrine query.', 'identifier' => 'doctrine.queryInLoop', 'count' => 2, 'path' => $this->directory.'/src/A.php'],
            ['rawMessage' => "It's in B", 'identifier' => 'doctrine.queryInNestedLoop', 'count' => 1, 'path' => $this->directory.'/src/B.php'],
        ], $this->baseline());
        self::assertStringContainsString("'path' => __DIR__.'/src/A.php'", (string) file_get_contents($this->directory.'/baseline.php'));
    }

    public function testAddsToAnIncludedBaseline(): void
    {
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

    public function testRewritesABaselineThatIsNotIncluded(): void
    {
        $this->format([], [$this->finding('Old', 'src/A.php'), $this->finding('Fixed', 'src/A.php')]);

        // Not included, every finding shows up again: adding them would double the counts.
        $this->format([], [$this->finding('Old', 'src/A.php')]);

        self::assertSame([['rawMessage' => 'Old', 'identifier' => 'doctrine.queryInLoop', 'count' => 1, 'path' => $this->directory.'/src/A.php']], $this->baseline());
    }

    public function testLeavesAForeignFileAlone(): void
    {
        file_put_contents($this->directory.'/baseline.php', "<?php return 'something else';\n");

        $this->assertSame(1, (new BaselineErrorFormatter([]))->formatErrors(new AnalysisResult([$this->finding('A', 'src/A.php')], [], [], [], [], false, null, true, 0, false, []), $this->getOutput()));
        self::assertSame("<?php return 'something else';\n", file_get_contents($this->directory.'/baseline.php'));
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

    /** @return mixed */
    private function baseline(): mixed
    {
        $data = require $this->directory.'/baseline.php';
        self::assertIsArray($data);

        return $data['parameters']['ignoreErrors'];
    }

    private function finding(string $message, string $file, string $identifier = 'doctrine.queryInLoop'): Error
    {
        return new Error($message, $this->directory.'/'.$file, 1, true, null, null, null, null, null, $identifier, [Severity::METADATA_SCORE => 50]);
    }
}
