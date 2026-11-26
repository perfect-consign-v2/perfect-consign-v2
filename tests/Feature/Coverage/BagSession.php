<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectApp\Session\Session;

/**
 * Session values held in memory. It does not start the process session.
 */
class BagSession extends Session
{
    /** @param array<string, mixed> $bag */
    public function __construct(private array $bag = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->bag[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->bag[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->bag[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->bag);
    }

    public function destroy(): void
    {
        $this->bag = [];
    }

    public function regenerateId(bool $deleteOldSession = true): void
    {
    }
}
