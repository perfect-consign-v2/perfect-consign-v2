<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;

final class Actor
{
    public function __construct(private readonly Session $session)
    {
    }

    public function slug(): ?string
    {
        $slug = $this->session->get('owner_slug');
        if (!is_string($slug) || $slug === '') {
            return null;
        }
        if ($this->stale(time())) {
            $this->session->destroy();

            return null;
        }
        if ($this->role() === '') {
            return null;
        }
        $this->session->set('last_seen_at', (string) time());

        return $slug;
    }

    public function name(): string
    {
        $name = $this->session->get('owner_name');
        if (!is_string($name)) {
            return '';
        }
        $trimmed = trim($name);
        if ($trimmed === '' || strpbrk($trimmed, "\r\n") !== false) {
            return '';
        }

        return $trimmed;
    }

    public function role(): string
    {
        $role = $this->session->get('actor_role');
        if ($role === 'cashier' || $role === 'vendor' || $role === 'owner' || $role === 'books') {
            return $role;
        }

        return '';
    }

    public function account(): string
    {
        $account = $this->session->get('actor_account');
        if (!is_string($account) || preg_match('/^[0-9a-fA-F-]{36}$/', $account) !== 1) {
            return '';
        }

        return $account;
    }

    public function vendor(): string
    {
        $vendor = $this->session->get('actor_vendor');
        if (!is_string($vendor) || preg_match('/^[0-9a-fA-F-]{36}$/', $vendor) !== 1) {
            return '';
        }

        return $vendor;
    }

    public function path(string $slug): string
    {
        return match ($this->role()) {
            'cashier' => '/' . $slug . '/pos',
            'vendor' => '/' . $slug . '/vendor',
            'books' => '/' . $slug . '/sales',
            default => '/' . $slug . '/dashboard',
        };
    }

    public function permitted(string $slug, string $path, string $method): bool
    {
        $rest = $this->rest($slug, $path);
        if ($rest === null) {
            return false;
        }

        return RoleGate::allows($this->role(), $rest, $method);
    }

    private function rest(string $slug, string $path): ?string
    {
        $prefix = '/' . $slug;
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $rest = substr($path, strlen($prefix));
        if ($rest === '') {
            return '/';
        }

        return $rest;
    }

    private function stale(int $now): bool
    {
        return $this->older('signed_in_at', $now, 86400) || $this->older('last_seen_at', $now, 43200);
    }

    private function older(string $key, int $now, int $limit): bool
    {
        $value = $this->session->get($key);
        if (!is_string($value) || ctype_digit($value) !== true) {
            return true;
        }

        return $now - (int) $value >= $limit;
    }
}
