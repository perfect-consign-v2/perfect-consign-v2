<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDOException;
use RuntimeException;

final class DatabaseRefusal extends RuntimeException
{
    public const CERTIFICATE_NUMBER_USED = 'That certificate number is already used.';

    public const BARCODE_USED = 'That barcode is already used.';

    public const TICKET_NOT_A_SALE = 'that ticket is not a sale';

    public const COULD_NOT_SAVE = 'That could not be saved.';

    public static function fromPdo(PDOException $error): self
    {
        $sqlState = self::sqlState($error);
        $detail = self::errorDetail($error->getMessage());

        if (self::isUniqueNoise($sqlState, $detail)) {
            return new self(self::COULD_NOT_SAVE, 0, $error);
        }
        if ($detail !== '') {
            return new self(self::phrase($detail), 0, $error);
        }

        return new self(self::COULD_NOT_SAVE, 0, $error);
    }

    public static function phrase(string $message): string
    {
        $named = self::cashier($message);
        if ($named !== null) {
            return $named;
        }
        if (self::rawDatabaseNoise($message)) {
            return self::COULD_NOT_SAVE;
        }

        return $message;
    }

    public static function isBarcodeUsed(self $error): bool
    {
        return $error->getMessage() === self::BARCODE_USED;
    }

    public static function isCertificateNumberUsed(self $error): bool
    {
        return $error->getMessage() === self::CERTIFICATE_NUMBER_USED;
    }

    public static function isTicketNotASale(self $error): bool
    {
        return $error->getMessage() === self::TICKET_NOT_A_SALE;
    }

    private static function sqlState(PDOException $error): string
    {
        $info = $error->errorInfo;
        if (is_array($info) && isset($info[0]) && is_string($info[0]) && $info[0] !== '') {
            return $info[0];
        }
        if (preg_match('/SQLSTATE\[([A-Z0-9]+)\]/', $error->getMessage(), $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private static function errorDetail(string $message): string
    {
        if (preg_match('/ERROR:\s+(.+)/s', $message, $matches) !== 1) {
            return '';
        }
        $text = preg_split('/\r?\n|DETAIL:|CONTEXT:/', $matches[1]) ?: [];

        return trim((string) ($text[0] ?? ''));
    }

    private static function isUniqueNoise(string $sqlState, string $detail): bool
    {
        if ($sqlState !== '23505') {
            return false;
        }
        if ($detail === '') {
            return true;
        }

        return self::rawDatabaseNoise($detail);
    }

    private static function cashier(string $line): ?string
    {
        $lower = strtolower($line);
        $known = [
            'there are not enough on hand' => 'There aren\'t that many left.',
            'there is no open ticket' => 'Start a sale, then try again.',
            'a layaway deposit cannot pass the amount due' => 'That amount is too big.',
        ];
        foreach ($known as $needle => $sentence) {
            if ($lower === $needle || str_starts_with($lower, $needle)) {
                return $sentence;
            }
        }
        if (
            str_contains($lower, 'numeric field overflow')
            || str_contains($lower, 'out of range')
            || preg_match('/\bvalue too large\b/', $lower) === 1
        ) {
            return 'That amount is too big.';
        }

        return null;
    }

    /** Constraint dumps and SQLSTATE lines are not cashier copy. Product RAISE English passes. */
    private static function rawDatabaseNoise(string $line): bool
    {
        $lower = strtolower($line);
        if (str_starts_with($lower, 'duplicate key value')) {
            return true;
        }
        if (str_contains($lower, 'violates check constraint') || str_contains($lower, 'violates unique constraint')) {
            return true;
        }
        if (str_contains($lower, 'sqlstate[')) {
            return true;
        }
        if (preg_match('/\b[a-z0-9_]+_(?:chk|key|pkey|fkey|idx)\b/i', $line) === 1) {
            $first = substr($line, 0, 1);
            if ($first === '' || strtolower($first) === $first) {
                return true;
            }
        }

        return false;
    }
}
