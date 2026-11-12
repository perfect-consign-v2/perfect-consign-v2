<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectApp\Logger\FileLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MirrorCli
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $root,
        private readonly string $artifactRoot,
        private readonly string $sqlDirectory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? $this->fileLogger();
    }

    private function fileLogger(): LoggerInterface
    {
        $logDirectory = $this->root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
            throw new RuntimeException('the log directory could not be created');
        }

        return new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'app.log');
    }

    private function pipeline(): MirrorPipeline
    {
        return new MirrorPipeline($this->root, $this->sqlDirectory, $this->logger);
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        $options = $this->options(array_slice($argv, 2));

        return match ($command) {
            'extract' => $this->extract($options),
            'load' => $this->load($options),
            'prove' => $this->prove($options),
            'refresh' => $this->refresh($options),
            default => $this->usage(),
        };
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function extract(array $options): int
    {
        $slug = $this->string($options, 'slug', MirrorConstants::SOURCE_SLUG);
        $artifact = (new Pc1Extractor(Pc1ReadOnlyConnection::fromEnv()))->extract($slug, $this->artifactRoot);
        fwrite(STDOUT, $artifact->path() . "\n");

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function load(array $options): int
    {
        $extract = $this->string($options, 'extract', '');
        if ($extract === '') {
            throw new MirrorException('--extract=path is required');
        }
        if (!($options['wipe'] ?? false)) {
            throw new MirrorException('--wipe is required for load');
        }
        $journalId = $this->pipeline()->load(
            $extract,
            $this->string($options, 'target', 'local'),
            $this->string($options, 'date', gmdate('Y-m-d')),
        );
        fwrite(STDOUT, MirrorConstants::TARGET_SLUG . ' loaded opening=' . $journalId . "\n");

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function prove(array $options): int
    {
        $extract = $this->string($options, 'extract', '');
        if ($extract === '') {
            throw new MirrorException('--extract=path is required');
        }
        $failures = $this->pipeline()->prove(
            $extract,
            $this->string($options, 'target', 'local'),
        );
        if ($failures !== []) {
            fwrite(STDERR, implode("\n", $failures) . "\n");

            return 1;
        }
        $manifest = MirrorArtifact::open($extract)->readJson('manifest.json');
        fwrite(STDOUT, 'prove ok as_of=' . MirrorFields::text($manifest['as_of_utc'] ?? null) . "\n");

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function refresh(array $options): int
    {
        $slug = $this->string($options, 'slug', MirrorConstants::SOURCE_SLUG);
        $targetName = $this->string($options, 'target', 'local');
        $artifact = (new Pc1Extractor(Pc1ReadOnlyConnection::fromEnv()))->extract($slug, $this->artifactRoot);
        $code = $this->load([
            'extract' => $artifact->path(),
            'target' => $targetName,
            'wipe' => true,
            'date' => $this->string($options, 'date', gmdate('Y-m-d')),
        ]);
        if ($code !== 0) {
            return $code;
        }

        return $this->prove([
            'extract' => $artifact->path(),
            'target' => $targetName,
        ]);
    }

    private function usage(): int
    {
        fwrite(STDERR, "usage:\n"
            . "  php bin/pc1-mirror.php extract [--slug=beccas-bargains]\n"
            . "  php bin/pc1-mirror.php load --extract=DIR --target=local|v2 --wipe [--date=YYYY-MM-DD]\n"
            . "  php bin/pc1-mirror.php prove --extract=DIR --target=local|v2\n"
            . "  php bin/pc1-mirror.php refresh [--slug=beccas-bargains] --target=local|v2\n");

        return 1;
    }

    /**
     * @param list<string> $argv
     * @return array<string, string|bool>
     */
    private function options(array $argv): array
    {
        $options = [];
        foreach ($argv as $arg) {
            if ($arg === '--wipe') {
                $options['wipe'] = true;
                continue;
            }
            if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function string(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
