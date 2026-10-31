<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeServer;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;
use RuntimeException;

/**
 * Boots mall webprobe + php -S once for Codeception functional/acceptance.
 * Uses port 8099 so PHPUnit's ProbeServer on 8097 can run in parallel.
 */
final class ProbeHarness
{
    private const PORT = 8099;

    private static ?self $instance = null;

    private ?ProbeSurfaceSeed $seed = null;

    private function __construct(
        private readonly ProbeServer $server,
        private readonly string $root,
    ) {
    }

    public static function boot(string $root): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }
        ProbeMall::ensure($root);
        $server = ProbeServer::startOn($root, self::PORT);
        $harness = new self($server, $root);
        register_shutdown_function(static function () use ($harness): void {
            $harness->stop();
        });
        self::$instance = $harness;

        return $harness;
    }

    public static function require(): self
    {
        if (!self::$instance instanceof self) {
            throw new RuntimeException('Codeception ProbeHarness was not booted; set WEB_BASE_URL or load params.php');
        }

        return self::$instance;
    }

    public function baseUrl(): string
    {
        return $this->server->baseUrl();
    }

    public function root(): string
    {
        return $this->root;
    }

    public function client(): FormClient
    {
        return new FormClient($this->baseUrl());
    }

    public function seed(): ProbeSurfaceSeed
    {
        if ($this->seed instanceof ProbeSurfaceSeed) {
            return $this->seed;
        }
        $this->seed = ProbeSurfaceSeed::ensure($this->client());

        return $this->seed;
    }

    public function stop(): void
    {
        $this->server->stop();
        if (self::$instance === $this) {
            self::$instance = null;
        }
    }
}
