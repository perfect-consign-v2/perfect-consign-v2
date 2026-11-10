<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Ledger;

use PerfectConsign\Ledger\DatabaseRefusal;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DatabaseRefusalTest extends TestCase
{
    public function testAnEnglishRaisePassesThrough(): void
    {
        $error = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  That tag is already used.'
        );

        self::assertSame('That tag is already used.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testABlankColorNameRaisePassesThrough(): void
    {
        $error = new PDOException(
            'SQLSTATE[23514]: Check violation: 7 ERROR:  Enter a color name.'
        );

        self::assertSame('Enter a color name.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testABlankBrandNameRaisePassesThrough(): void
    {
        $error = new PDOException(
            'SQLSTATE[23514]: Check violation: 7 ERROR:  Enter a brand name.'
        );

        self::assertSame('Enter a brand name.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAConstraintNameDumpIsHidden(): void
    {
        $error = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "journals_book_key"'
        );

        self::assertSame(DatabaseRefusal::COULD_NOT_SAVE, DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAnOverlappingBoothRaisePassesThrough(): void
    {
        $error = new PDOException(
            'SQLSTATE[23P01]: Exclusion violation: 7 ERROR:  That booth is already taken on those dates.'
        );

        self::assertSame('That booth is already taken on those dates.', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAPlainRefusalPassesThrough(): void
    {
        $error = new PDOException('SQLSTATE[P0001]: Raise exception: 7 ERROR:  a check sale needs a check number');

        self::assertSame('a check sale needs a check number', DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testAMessageWithoutAnErrorLineIsHidden(): void
    {
        $error = new PDOException('SQLSTATE[08006] connection failed');

        self::assertSame(DatabaseRefusal::COULD_NOT_SAVE, DatabaseRefusal::fromPdo($error)->getMessage());
    }

    public function testABarcodeRaiseIsRecognizedByHelper(): void
    {
        $error = new PDOException(
            'SQLSTATE[P0001]: Raise exception: 7 ERROR:  That barcode is already used.'
        );
        $refusal = DatabaseRefusal::fromPdo($error);

        self::assertTrue(DatabaseRefusal::isBarcodeUsed($refusal));
        self::assertFalse(DatabaseRefusal::isCertificateNumberUsed($refusal));
        self::assertTrue(DatabaseRefusal::isTicketNotASale(new DatabaseRefusal(DatabaseRefusal::TICKET_NOT_A_SALE)));
        self::assertFalse(DatabaseRefusal::isTicketNotASale($refusal));
    }

    public function testNotEnoughOnHandIsAPlainSentence(): void
    {
        self::assertSame('There aren\'t that many left.', DatabaseRefusal::phrase('there are not enough on hand'));
    }

    public function testNoiseAndOverflowAreHiddenOrMapped(): void
    {
        self::assertSame('That amount is too big.', DatabaseRefusal::phrase('numeric field overflow'));
        self::assertSame(DatabaseRefusal::COULD_NOT_SAVE, DatabaseRefusal::phrase('mall_clock_chk'));
        self::assertSame('That amount is too big.', DatabaseRefusal::phrase('value too large for the column'));
        self::assertSame(DatabaseRefusal::COULD_NOT_SAVE, DatabaseRefusal::phrase('duplicate key value'));
        self::assertSame(DatabaseRefusal::COULD_NOT_SAVE, DatabaseRefusal::phrase('violates check constraint colors_name'));
        self::assertSame(
            'journal 01900000-0000-7000-8000-000000000001',
            DatabaseRefusal::phrase('journal 01900000-0000-7000-8000-000000000001'),
        );
    }
}
