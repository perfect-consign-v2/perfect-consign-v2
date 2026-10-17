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

    public static function phrase(string $message): string
    {
        $named = self::cashier($message);
        if ($named !== null) {
            return $named;
        }
        if (self::databaseShaped($message)) {
            return 'That could not be saved.';
        }

        return $message;
    }

    private static function shown(string $first, string $raw): string
    {
        $named = self::uniqueName($raw);
        if ($named !== null) {
            return $named;
        }

        return self::phrase($first);
    }

    private static function cashier(string $line): ?string
    {
        $lower = strtolower($line);
        $known = [
            'there are not enough on hand' => 'That tag is already sold.',
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

    private static function databaseShaped(string $line): bool
    {
        $lower = strtolower($line);
        if (str_contains($lower, 'duplicate key') || str_contains($lower, 'violates') || str_contains($lower, 'sqlstate')) {
            return true;
        }
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $line) === 1) {
            return true;
        }
        if (preg_match('/\b[a-z0-9_]+_(?:chk|key|pkey|fkey|idx)\b/i', $line) === 1) {
            return true;
        }

        return preg_match('/\b[a-z0-9_]+:[0-9a-f-]{8,}/i', $line) === 1;
    }

    private static function uniqueName(string $raw): ?string
    {
        $names = [
            'staff_username_key' => 'That sign-in name is already used.',
            'registers_name_key' => 'Another register already has that name.',
            'stored_values_number_key' => self::CERTIFICATE_NUMBER_USED,
            'vendors_code_key' => 'That code is already used.',
            'booths_code_key' => 'That booth is already used.',
            'booth_assignments_no_overlap' => 'That booth is already taken on those dates.',
            'items_code_key' => 'That tag is already used.',
            'items_barcode_key' => 'That barcode is already used.',
            'brands_name_key' => 'That name is already used.',
            'colors_name_key' => 'That name is already used.',
            'categories_name_key' => 'That name is already used.',
            'sizes_name_key' => 'That name is already used.',
            'conditions_name_key' => 'That name is already used.',
            'departments_name_key' => 'That name is already used.',
            'map_floors_name_key' => 'That floor name is already used.',
            'wait_list_open_email_key' => 'That email is already on the wait list.',
        ];
        foreach ($names as $key => $sentence) {
            if (str_contains($raw, $key)) {
                return $sentence;
            }
        }

        return null;
    }
}
