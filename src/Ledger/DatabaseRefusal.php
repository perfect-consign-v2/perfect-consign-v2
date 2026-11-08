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

    public static function fromPdo(PDOException $error): self
    {
        $message = $error->getMessage();
        if (preg_match('/ERROR:\s+(.+)/s', $message, $matches) === 1) {
            $text = preg_split('/\r?\n|DETAIL:|CONTEXT:/', $matches[1]) ?: [];
            $first = trim((string) ($text[0] ?? ''));
            if ($first !== '') {
                return new self(self::phrase($first), 0, $error);
            }
        }

        return new self('That could not be saved.', 0, $error);
    }

    public static function phrase(string $message): string
    {
        $named = self::cashier($message);
        if ($named !== null) {
            return $named;
        }
        if (self::rawDatabaseNoise($message)) {
            return 'That could not be saved.';
        }

        return $message;
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
            if (str_contains($lower, $needle)) {
                return $sentence;
            }
        }
        if (str_contains($lower, 'too large') || str_contains($lower, 'out of range') || str_contains($lower, 'overflow')) {
            return 'That amount is too big.';
        }

        return null;
    }

    /** Hide SQLSTATE / constraint-name dumps; plain English ERROR text passes through. */
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
