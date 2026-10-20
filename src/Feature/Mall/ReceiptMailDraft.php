<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class ReceiptMailDraft
{
    public static function via(string $raw): string
    {
        $via = trim($raw);
        if ($via !== 'server' && $via !== 'gmail') {
            throw new \InvalidArgumentException('choose the server or Gmail');
        }

        return $via;
    }

    public static function address(string $raw): ?string
    {
        $email = trim($raw);
        if ($email === '') {
            return null;
        }
        if (strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            throw new \InvalidArgumentException('that Gmail address is not an email address');
        }
        if (!str_ends_with(strtolower($email), '@gmail.com') && !str_ends_with(strtolower($email), '@googlemail.com')) {
            throw new \InvalidArgumentException('that address is not a Gmail address');
        }

        return $email;
    }

    public static function password(string $raw): ?string
    {
        $password = str_replace(' ', '', trim($raw));
        if ($password === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9]{16,64}$/', $password) !== 1) {
            throw new \InvalidArgumentException('a Gmail app password is 16 letters or digits');
        }

        return $password;
    }
}
