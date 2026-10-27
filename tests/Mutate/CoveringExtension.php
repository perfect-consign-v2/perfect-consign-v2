<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Mutate;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber as TestFinishedSubscriber;
use PHPUnit\Event\Test\Prepared as TestPrepared;
use PHPUnit\Event\Test\PreparedSubscriber as TestPreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Records which unit tests execute each covered src line for mutant scoring.
 */
final class CoveringExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('PC_MUTATE_COVERING') !== '1') {
            return;
        }
        if (!function_exists('xdebug_start_code_coverage') || !defined('XDEBUG_FILTER_CODE_COVERAGE')) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $source = $root . DIRECTORY_SEPARATOR . 'src';
        $out = getenv('PC_MUTATE_COVERING_OUT');
        if (!is_string($out) || $out === '') {
            $out = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mutation' . DIRECTORY_SEPARATOR . 'covering.json';
        }
        $collector = new CoveringCollector($root, $source, $out);
        $facade->registerSubscriber(new CoveringPreparedSubscriber($collector));
        $facade->registerSubscriber(new CoveringFinishedSubscriber($collector));
        register_shutdown_function(static function () use ($collector): void {
            $collector->write();
        });
    }
}

/**
 * @internal
 */
final class CoveringCollector
{
    /** @var array<string, array<int, list<string>>> */
    private array $covering = [];

    public function __construct(
        private readonly string $root,
        private readonly string $source,
        private readonly string $outFile,
    ) {
    }

    public function prepare(): void
    {
        if (function_exists('xdebug_stop_code_coverage')) {
            xdebug_stop_code_coverage(true);
        }
        xdebug_set_filter(
            XDEBUG_FILTER_CODE_COVERAGE,
            XDEBUG_PATH_INCLUDE,
            [$this->source]
        );
        xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
    }

    public function finish(string $testId): void
    {
        if (!function_exists('xdebug_get_code_coverage')) {
            return;
        }
        $data = xdebug_get_code_coverage();
        xdebug_stop_code_coverage(true);
        if ($testId === '') {
            return;
        }
        $src = strtolower(str_replace('\\', '/', $this->source . '/'));
        $prefix = rtrim(str_replace('\\', '/', $this->root), '/') . '/';
        foreach ($data as $file => $lines) {
            if (!is_string($file) || !is_array($lines)) {
                continue;
            }
            $real = realpath($file);
            if ($real === false) {
                continue;
            }
            $key = strtolower(str_replace('\\', '/', $real));
            if (!str_starts_with($key, $src)) {
                continue;
            }
            $relative = str_replace('/', '\\', substr(str_replace('\\', '/', $real), strlen($prefix)));
            foreach ($lines as $line => $count) {
                if (!is_int($line) || !is_int($count) || $count < 1) {
                    continue;
                }
                $known = $this->covering[$relative][$line] ?? [];
                if (!in_array($testId, $known, true)) {
                    $known[] = $testId;
                }
                $this->covering[$relative][$line] = $known;
            }
        }
    }

    public function write(): void
    {
        if ($this->covering === []) {
            return;
        }
        $dir = dirname($this->outFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            fwrite(STDERR, "covering directory could not be created\n");

            return;
        }
        $encoded = json_encode($this->covering, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($this->outFile, $encoded . "\n") === false) {
            fwrite(STDERR, "covering map could not be written\n");
        }
    }
}

/**
 * @internal
 */
final class CoveringPreparedSubscriber implements TestPreparedSubscriber
{
    public function __construct(private readonly CoveringCollector $collector)
    {
    }

    public function notify(TestPrepared $event): void
    {
        $this->collector->prepare();
    }
}

/**
 * @internal
 */
final class CoveringFinishedSubscriber implements TestFinishedSubscriber
{
    public function __construct(private readonly CoveringCollector $collector)
    {
    }

    public function notify(TestFinished $event): void
    {
        $test = $event->test();
        if (!$test instanceof TestMethod) {
            return;
        }
        $this->collector->finish($test->className() . '::' . $test->methodName());
    }
}
