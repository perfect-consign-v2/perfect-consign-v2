<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Ledger\DatabaseRefusal;

final class SignedInPerson
{
    public static function require(string $name, string $role): void
    {
        if ($name === '' || $role === '') {
            throw new DatabaseRefusal('the signed-in person is missing');
        }
    }
}
