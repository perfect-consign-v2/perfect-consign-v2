<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Ledger;

use PerfectConsign\Ledger\DatabaseRefusal;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DatabaseRefusalTest extends TestCase
{
    public function testADuplicateTagIsASentence(): void
    {
        $error = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "items_code_key"'
        );

        self::assertSame('That tag is already used.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testABlankColorNameIsASentence(): void
    {
        $error = new PDOException(
            'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "colors" violates check constraint "colors_name_chk"'
        );

        self::assertSame('Enter a color name.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testABlankBrandNameIsASentence(): void
    {
        $error = new PDOException(
            'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "brands" violates check constraint "brands_name_chk"'
        );

        self::assertSame('Enter a brand name.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAnUnmappedDatabaseLineIsHidden(): void
    {
        $error = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "journals_book_key"'
        );

        self::assertSame('That could not be saved.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAnOverlappingBoothStayIsASentence(): void
    {
        $error = new PDOException(
            'SQLSTATE[23P01]: Exclusion violation: 7 ERROR:  conflicting key value violates exclusion constraint "booth_assignments_no_overlap"'
        );

        self::assertSame('That booth is already taken on those dates.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAPlainRefusalPassesThrough(): void
    {
        $error = new PDOException('SQLSTATE[P0001]: Raise exception: 7 ERROR:  a check sale needs a check number');

        self::assertSame('a check sale needs a check number', DatabaseRefusal::fromPdo($error)->getMessage());
    }
}
