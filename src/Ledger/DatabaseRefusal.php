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
        $named = self::uniqueName($raw);
        if ($named !== null) {
            return $named;
        }
        if (self::databaseShaped($first)) {
            return 'That could not be saved.';
        }

        return $first;
    }

    private static function databaseShaped(string $line): bool
    {
        return str_contains($line, 'duplicate key');
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
