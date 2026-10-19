<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class GiftIssueTest extends TestCase
{
    public function testAUnknownKindIsRefusedOnTheBalance(): void
    {
        $gifts = new GiftIssue(
            $this->createStub(Statements::class),
            $this->createStub(LedgerStore::class),
        );
        try {
            $gifts->balance('GC-1', 'coupon');
            self::fail('an unknown gift kind was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a gift certificate, a gift card, or store credit.', $error->getMessage());
        }
    }

    public function testABlankNumberHasNoBalance(): void
    {
        $gifts = new GiftIssue(
            $this->createStub(Statements::class),
            $this->createStub(LedgerStore::class),
        );

        self::assertNull($gifts->balance('   ', 'store_credit'));
    }

    public function testAMissingJournalIsNotAGiftSlip(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn(null);
        $gifts = new GiftIssue($this->createStub(Statements::class), $ledger);
        try {
            $gifts->slip('00000000-0000-7000-8000-0000000000cc');
            self::fail('a missing journal was treated as a gift');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that journal is not a gift certificate', $error->getMessage());
        }
    }

    public function testASaleJournalIsNotAGiftSlip(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_reference' => 'GC-1',
            'source_type' => 'sale',
            'is_reversal' => false,
            'posting_date' => '2026-10-01',
        ]);
        $gifts = new GiftIssue($this->createStub(Statements::class), $ledger);
        try {
            $gifts->slip('00000000-0000-7000-8000-0000000000cc');
            self::fail('a sale journal was treated as a gift');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that journal is not a gift certificate', $error->getMessage());
        }
    }

    public function testAnIssueAllocatesACardNumberAndPosts(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000aa']]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        $ledger->method('post')->willReturn('00000000-0000-7000-8000-0000000000j1');
        $gifts = new GiftIssue($statements, $ledger);

        self::assertSame(
            '00000000-0000-7000-8000-0000000000j1',
            $gifts->issue('sess', '2026-10-01', '', 500, 'card', null, 'gift_card'),
        );
    }

    public function testAnIssueNeedsACheckNumber(): void
    {
        $gifts = new GiftIssue(
            $this->createStub(Statements::class),
            $this->createStub(LedgerStore::class),
        );
        try {
            $gifts->issue('sess', '2026-10-01', 'GC-9', 100, 'checks', '');
            self::fail('a check issue without a number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame(GiftIssue::CHECK_REQUIRED, $error->getMessage());
        }
    }

    public function testAnIssueRefusesALongNumberAndAZeroAmount(): void
    {
        $gifts = new GiftIssue(
            $this->createStub(Statements::class),
            $this->createStub(LedgerStore::class),
        );
        try {
            $gifts->issue('sess', '2026-10-01', str_repeat('n', 41), 100, 'cash', null);
            self::fail('a long stored-value number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a stored value number is required', $error->getMessage());
        }
        try {
            $gifts->issue('sess', '2026-10-01', 'GC-9', 0, 'cash', null);
            self::fail('a zero issue was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('stored value is issued for cash, card, or a check', $error->getMessage());
        }
    }

    public function testAnEmptyPostedJournalIsNotAGift(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000aa']]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        $ledger->method('post')->willReturn('');
        $gifts = new GiftIssue($statements, $ledger);
        try {
            $gifts->issue('sess', '2026-10-01', 'GC-empty', 100, 'cash', null);
            self::fail('an empty journal id was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that journal is not a gift certificate', $error->getMessage());
        }
    }

    public function testAUsedNumberStopsRetryingWhenTheOperatorChoseIt(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (): void {
            throw new DatabaseRefusal(DatabaseRefusal::CERTIFICATE_NUMBER_USED);
        });
        $gifts = new GiftIssue($statements, $this->createStub(LedgerStore::class));
        try {
            $gifts->issue('sess', '2026-10-01', 'GC-used', 100, 'cash', null);
            self::fail('a used certificate number was retried');
        } catch (DatabaseRefusal $error) {
            self::assertSame(DatabaseRefusal::CERTIFICATE_NUMBER_USED, $error->getMessage());
        }
    }

    public function testAnAllocatedNumberGivesUpAfterFiveCollisions(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (): void {
            throw new DatabaseRefusal(DatabaseRefusal::CERTIFICATE_NUMBER_USED);
        });
        $gifts = new GiftIssue($statements, $this->createStub(LedgerStore::class));
        try {
            $gifts->issue('sess', '2026-10-01', '', 100, 'cash', null);
            self::fail('five collisions were accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame(DatabaseRefusal::CERTIFICATE_NUMBER_USED, $error->getMessage());
        }
    }

    public function testAFailedPostRemovesTheStoredValue(): void
    {
        $deleted = false;
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000aa']]);
        $statements->method('change')->willReturnCallback(static function () use (&$deleted): void {
            $deleted = true;
        });
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        $ledger->method('post')->willReturnCallback(static function (): never {
            throw new \RuntimeException('post failed');
        });
        $gifts = new GiftIssue($statements, $ledger);
        try {
            $gifts->issue('sess', '2026-10-01', 'GC-fail', 100, 'cash', null);
            self::fail('a failed post was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('post failed', $error->getMessage());
        }
        self::assertTrue($deleted);
    }

    public function testAGiftCardSlipNeedsAnIssuedAmount(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['balance' => 0]]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_reference' => 'GD-1',
            'source_type' => 'gift_card_issue',
            'is_reversal' => false,
            'posting_date' => new \DateTimeImmutable('2026-10-01'),
        ]);
        $ledger->method('lines')->willReturn([
            ['subledger_type' => 'cash', 'credit_minor' => 500],
            ['subledger_type' => 'gift_card', 'credit_minor' => 0],
        ]);
        $gifts = new GiftIssue($statements, $ledger);
        try {
            $gifts->slip('00000000-0000-7000-8000-0000000000cc');
            self::fail('a gift without an issued amount was shown');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that journal is not a gift certificate', $error->getMessage());
        }
    }

    public function testAStoreCreditSlipShowsThePostedAmount(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['balance' => 400]]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_reference' => 'SC-1',
            'source_type' => 'store_credit_issue',
            'is_reversal' => false,
            'posting_date' => '2026-10-02',
        ]);
        $ledger->method('lines')->willReturn([
            ['subledger_type' => 'store_credit', 'credit_minor' => 400],
        ]);
        $ledger->method('reversed')->willReturn(false);
        $gifts = new GiftIssue($statements, $ledger);
        $slip = $gifts->slip('00000000-0000-7000-8000-0000000000cc');
        self::assertSame('SC-1', $slip['number']);
        self::assertSame(400, $slip['amount']);
        self::assertSame('Store credit', $slip['label']);
    }

    public function testAGiftCardSlipUsesTheCardLabel(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['balance' => 500]]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_reference' => 'GD-1',
            'source_type' => 'gift_card_issue',
            'is_reversal' => false,
            'posting_date' => '2026-10-02',
        ]);
        $ledger->method('lines')->willReturn([
            ['subledger_type' => 'gift_card', 'credit_minor' => 500],
        ]);
        $ledger->method('reversed')->willReturn(false);
        $gifts = new GiftIssue($statements, $ledger);
        $slip = $gifts->slip('00000000-0000-7000-8000-0000000000cc');
        self::assertSame('Gift card', $slip['label']);
        self::assertSame(500, $slip['balance']);
    }

    public function testAnAllocatedNumberIsTriedFiveTimes(): void
    {
        $tries = 0;
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function () use (&$tries): void {
            $tries++;
            throw new DatabaseRefusal(DatabaseRefusal::CERTIFICATE_NUMBER_USED);
        });
        $gifts = new GiftIssue($statements, $this->createStub(LedgerStore::class));
        try {
            $gifts->issue('sess', '2026-10-01', '', 100, 'cash', null);
            self::fail('five collisions were accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame(DatabaseRefusal::CERTIFICATE_NUMBER_USED, $error->getMessage());
        }
        self::assertSame(5, $tries);
    }

    public function testAChosenNumberIsNotAllocated(): void
    {
        $number = str_repeat('A', 40);
        $stored = null;
        $draft = null;
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('bound')->willReturnCallback(static function (string $sql, array $params) use (&$stored): array {
            $stored = $params;

            return [['id' => '00000000-0000-7000-8000-0000000000aa']];
        });
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('acct-cash');
        $ledger->method('post')->willReturnCallback(static function (JournalDraft $posted) use (&$draft): string {
            $draft = $posted;

            return '00000000-0000-7000-8000-0000000000j1';
        });
        $gifts = new GiftIssue($statements, $ledger);

        self::assertSame(
            '00000000-0000-7000-8000-0000000000j1',
            $gifts->issue('sess', '2026-10-01', $number, 1, 'cash', '999'),
        );
        self::assertIsArray($stored);
        self::assertSame('gift_certificate', $stored[0]);
        self::assertSame($number, $stored[1]);
        self::assertInstanceOf(JournalDraft::class, $draft);
        self::assertNull($draft->checkNumber);
        self::assertSame(1, $draft->lines[0]['debit']);
    }

    public function testAChosenNumberStopsOnTheFirstCollision(): void
    {
        $tries = 0;
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function () use (&$tries): void {
            $tries++;
            throw new DatabaseRefusal(DatabaseRefusal::CERTIFICATE_NUMBER_USED);
        });
        $gifts = new GiftIssue($statements, $this->createStub(LedgerStore::class));
        try {
            $gifts->issue('sess', '2026-10-01', 'GC-used', 100, 'cash', null);
            self::fail('a used certificate number was retried');
        } catch (DatabaseRefusal $error) {
            self::assertSame(DatabaseRefusal::CERTIFICATE_NUMBER_USED, $error->getMessage());
        }
        self::assertSame(1, $tries);
    }

    public function testAnAllocatedNumberDoesNotSwallowAnotherRefusal(): void
    {
        $tries = 0;
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function () use (&$tries): void {
            $tries++;
            throw new DatabaseRefusal('the mall has no clock');
        });
        $gifts = new GiftIssue($statements, $this->createStub(LedgerStore::class));
        try {
            $gifts->issue('sess', '2026-10-01', '', 100, 'cash', null);
            self::fail('a mall refusal was retried');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the mall has no clock', $error->getMessage());
        }
        self::assertSame(1, $tries);
    }

    public function testOneCentIsEnoughToShowTheSlip(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['balance' => 1]]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_reference' => 'GC-1',
            'source_type' => 'gift_certificate_issue',
            'is_reversal' => false,
            'posting_date' => '2026-10-01',
        ]);
        $ledger->method('lines')->willReturn([
            ['subledger_type' => 'gift_certificate', 'credit_minor' => 0],
            ['subledger_type' => 'gift_certificate', 'credit_minor' => 1],
        ]);
        $ledger->method('reversed')->willReturn(false);
        $slip = (new GiftIssue($statements, $ledger))->slip('00000000-0000-7000-8000-0000000000cc');

        self::assertSame(1, $slip['amount']);
        self::assertSame(1, $slip['balance']);
    }
}
