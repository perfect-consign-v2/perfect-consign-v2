<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDOException;
use RuntimeException;

final class DatabaseRefusal extends RuntimeException
{
    public const CERTIFICATE_NUMBER_USED = 'That certificate number is already used.';

    public static function fromPdo(PDOException $error): self
    {
        $message = $error->getMessage();
        if (preg_match('/ERROR:\s+(.+)/s', $message, $matches) === 1) {
            $text = preg_split('/\r?\n|DETAIL:|CONTEXT:/', $matches[1]) ?: [];
            $first = trim((string) ($text[0] ?? ''));
            if ($first !== '') {
                return new self(self::shown($first, $message), 0, $error);
            }
        }

        return new self('That could not be saved.', 0, $error);
    }

    private static function shown(string $first, string $raw): string
    {
        if (str_contains($raw, 'registers_name_key')) {
            return 'Another register already has that name.';
        }
        if (str_contains($raw, 'stored_values_number_key')) {
            return self::CERTIFICATE_NUMBER_USED;
        }

        return $first;
    }
}
