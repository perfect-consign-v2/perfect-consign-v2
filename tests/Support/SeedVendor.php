<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PDO;

/** Party-model vendor seed for tests (money key = vendors.id). */
final class SeedVendor
{
    public static function withId(
        Statements $statements,
        string $id,
        string $code,
        string $first,
        string $last,
        ?string $email = null,
        bool $taxExempt = false,
        ?string $taxExemptNumber = null,
    ): void {
        $exempt = $taxExempt ? 'true' : 'false';
        $statements->change(
            "SELECT stall_seed_vendor(
                ?::uuid, ?::text, ?::text, ?::text, NULL::integer, {$exempt}::boolean, ?::text, ?::text
             )",
            [$id, $code, $first, $last, $taxExemptNumber, $email]
        );
    }

    public static function create(
        Statements $statements,
        string $code,
        string $first,
        string $last,
        ?string $email = null,
    ): string {
        $rows = $statements->rows('SELECT uuidv7() AS id');
        $id = RowValue::string($rows[0]['id'] ?? null);
        self::withId($statements, $id, $code, $first, $last, $email);

        return $id;
    }

    public static function withIdPdo(
        PDO $pdo,
        string $id,
        string $code,
        string $first,
        string $last,
    ): void {
        $statement = $pdo->prepare(
            'SELECT stall_seed_vendor(
                ?::uuid, ?::text, ?::text, ?::text, NULL::integer, false, NULL::text, NULL::text
             )'
        );
        $statement->execute([$id, $code, $first, $last]);
    }
}
