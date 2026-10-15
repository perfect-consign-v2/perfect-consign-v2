<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

final class PasswordChoice
{
    public function hash(string $password): string
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Use a password of at least 8 characters.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function current(string $current, string $currentHash): void
    {
        if (!password_verify($current, $currentHash)) {
            throw new \InvalidArgumentException('That password is wrong.');
        }
    }

    public function changed(string $current, string $currentHash, string $password, string $confirm): string
    {
        $this->current($current, $currentHash);
        if ($password !== $confirm) {
            throw new \InvalidArgumentException('Those new passwords do not match.');
        }
        if (password_verify($password, $currentHash)) {
            throw new \InvalidArgumentException('Choose a different password.');
        }

        return $this->hash($password);
    }

    public function replacement(string $currentHash, string $password, string $confirm): string
    {
        if ($password !== $confirm) {
            throw new \InvalidArgumentException('Those new passwords do not match.');
        }
        if (password_verify($password, $currentHash)) {
            throw new \InvalidArgumentException('Choose a different password.');
        }

        return $this->hash($password);
    }
}
