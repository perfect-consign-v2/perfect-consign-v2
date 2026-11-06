<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

final class MirrorArtifact
{
    public function __construct(private readonly string $directory)
    {
        if ($directory === '' || !is_dir($directory)) {
            throw new MirrorException('mirror artifact directory is missing');
        }
    }

    public static function create(string $root, string $slug, string $asOfUtc): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new MirrorException('artifact slug is invalid');
        }
        if (preg_match('/^\d{8}T\d{6}Z$/', $asOfUtc) !== 1) {
            throw new MirrorException('artifact as-of stamp is invalid');
        }
        $directory = $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . $asOfUtc;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new MirrorException('could not create artifact directory');
        }
        $master = $directory . DIRECTORY_SEPARATOR . 'master';
        if (!is_dir($master) && !mkdir($master, 0700) && !is_dir($master)) {
            throw new MirrorException('could not create master directory');
        }

        return new self($directory);
    }

    public static function open(string $directory): self
    {
        return new self(rtrim($directory, "\\/"));
    }

    public function path(): string
    {
        return $this->directory;
    }

    /**
     * @param array<mixed> $data
     */
    public function writeJson(string $relative, array $data): void
    {
        $path = $this->resolve($relative);
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new MirrorException('could not create artifact path');
        }
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($path, $encoded . "\n") === false) {
            throw new MirrorException('could not write ' . $relative);
        }
    }

    /**
     * @return array<mixed>
     */
    public function readJson(string $relative): array
    {
        $path = $this->resolve($relative);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new MirrorException('could not read ' . $relative);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new MirrorException($relative . ' is not a JSON object');
        }

        return $decoded;
    }

    public function checksum(): string
    {
        $files = ['manifest.json', 'balances.json'];
        foreach (['vendors.json', 'booths.json', 'assignments.json', 'items.json', 'settings.json', 'stored_values.json'] as $name) {
            $files[] = 'master/' . $name;
        }
        $hash = hash_init('sha256');
        foreach ($files as $relative) {
            $path = $this->resolve($relative);
            if (!is_file($path)) {
                continue;
            }
            $body = file_get_contents($path);
            if ($body === false) {
                throw new MirrorException('could not hash ' . $relative);
            }
            hash_update($hash, $relative . "\n" . $body);
        }

        return hash_final($hash);
    }

    private function resolve(string $relative): string
    {
        $relative = str_replace(['\\', '..'], ['/', ''], $relative);
        if ($relative === '' || str_starts_with($relative, '/')) {
            throw new MirrorException('artifact path is invalid');
        }

        return $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
