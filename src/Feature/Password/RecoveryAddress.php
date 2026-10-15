<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

final class RecoveryAddress
{
    public function parse(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            throw new \InvalidArgumentException('Enter a recovery email.');
        }

        return $email;
    }
}
