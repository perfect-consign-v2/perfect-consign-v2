<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use RuntimeException;

/**
 * The store name, owner email, and password a signup asks for, checked the same
 * way for the public form and for an operator.
 */
final class SignupInput
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function checked(string $storeName, string $email, string $password, string $confirm): array
    {
        $storeName = trim($storeName);
        $email = strtolower(trim($email));
        if ($storeName === '' || strlen($storeName) > 120) {
            throw new RuntimeException('Enter a store name.');
        }
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        if ($password !== $confirm) {
            throw new RuntimeException('Password confirmation does not match.');
        }

        return [$storeName, $email];
    }
}
