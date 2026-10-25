<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Session\Session;

final class AdminSession
{
    public const ID = 'platform_admin_id';

    public const EMAIL = 'platform_admin_email';

    public const VERSION = 'platform_credentials_version';

    public function __construct(private readonly Session $session)
    {
    }

    public function open(string $id, string $email, string $version): void
    {
        $this->session->regenerateId(true);
        $this->session->set(self::ID, $id);
        $this->session->set(self::EMAIL, $email);
        $this->session->set(self::VERSION, $version);
        $this->session->set('csrf', bin2hex(random_bytes(16)));
        $this->session->set('platform_signed_in_at', (string) time());
        $this->session->set('platform_last_seen_at', (string) time());
    }

    public function stamp(string $version): void
    {
        $this->session->regenerateId(true);
        $this->session->set(self::VERSION, $version);
        $this->session->set('csrf', bin2hex(random_bytes(16)));
        $this->session->set('platform_last_seen_at', (string) time());
    }

    public function clear(): void
    {
        $this->session->delete(self::ID);
        $this->session->delete(self::EMAIL);
        $this->session->delete(self::VERSION);
        $this->session->delete('platform_signed_in_at');
        $this->session->delete('platform_last_seen_at');
    }

    /**
     * @return array{id: string, email: string, version: string}|null
     */
    public function current(): ?array
    {
        $id = $this->session->get(self::ID);
        $email = $this->session->get(self::EMAIL);
        $version = $this->session->get(self::VERSION);
        if (!is_string($id) || $id === '' || !is_string($email) || $email === '' || !is_string($version) || $version === '') {
            return null;
        }

        return ['id' => $id, 'email' => $email, 'version' => $version];
    }

    public function touch(): void
    {
        $this->session->set('platform_last_seen_at', (string) time());
    }

    public function stale(int $now): bool
    {
        return $this->older('platform_signed_in_at', $now, 28800)
            || $this->older('platform_last_seen_at', $now, 3600);
    }

    private function older(string $key, int $now, int $limit): bool
    {
        $value = $this->session->get($key);
        if (!is_string($value) || ctype_digit($value) !== true) {
            return true;
        }

        return $now - (int) $value >= $limit;
    }

    /**
     * @return array{id: string, email: string, version: string}
     */
    public function snapshot(): array
    {
        $current = $this->current();
        if ($current === null) {
            throw new \RuntimeException('the platform admin session is missing');
        }

        return $current;
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    public function restore(array $admin): void
    {
        $this->session->set(self::ID, $admin['id']);
        $this->session->set(self::EMAIL, $admin['email']);
        $this->session->set(self::VERSION, $admin['version']);
    }
}
