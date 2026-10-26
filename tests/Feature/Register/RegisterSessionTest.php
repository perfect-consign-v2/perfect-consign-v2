<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Register;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterSession;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class RegisterSessionTest extends TestCase
{
    public function testTheOpeningCountMustMatchTheTypedFloat(): void
    {
        $session = $this->session($this->createStub(Statements::class), $this->createStub(LedgerStore::class));
        try {
            $session->open('reg', 'Ada', [100 => 1], 500);
            self::fail('a mismatched opening float was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the bills and coins must add up to the opening float', $error->getMessage());
        }
    }

    public function testABlankInsertDoesNotOpen(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $session = $this->session($statements, $this->createStub(LedgerStore::class));
        try {
            $session->open('reg', 'Ada', [], 0);
            self::fail('an empty insert opened a register');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the register did not open', $error->getMessage());
        }
    }

    public function testACashierNameIsRequired(): void
    {
        $session = $this->session($this->createStub(Statements::class), $this->createStub(LedgerStore::class));
        try {
            $session->open('reg', '', [], 0);
            self::fail('a blank cashier was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a cashier name is required', $error->getMessage());
        }
        try {
            $session->open('reg', ' Ada', [], 0);
            self::fail('a padded cashier was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a cashier name is required', $error->getMessage());
        }
        try {
            $session->open('reg', str_repeat('A', 81), [], 0);
            self::fail('an 81 character cashier was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a cashier name is required', $error->getMessage());
        }
        $opened = $this->createStub(Statements::class);
        $opened->method('bound')->willReturn([['id' => 'sess']]);
        self::assertSame('sess', $this->session($opened, $this->createStub(LedgerStore::class))->open('reg', str_repeat('A', 80), [], 0));
    }

    public function testAnEvenCloseDoesNotPostAVariance(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'stall_register_expected_cash')) {
                return [['expected' => 100]];
            }
            if (str_contains($sql, 'closed_at IS NULL')) {
                return [['id' => 'sess']];
            }

            return [];
        });
        $statements->method('affected')->willReturn(1);
        $posted = false;
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('post')->willReturnCallback(static function () use (&$posted): string {
            $posted = true;

            return 'journal';
        });
        self::assertSame('sess', $this->session($statements, $ledger)->close('reg', [], 100, 0, 0, false, '2026-10-10'));
        self::assertFalse($posted);
    }

    public function testADropOnTheOpenDayIsAllowed(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'id' => 'sess',
            'opened_at' => '2026-10-10 12:00:00+00',
        ]]);
        $posted = false;
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('newId')->willReturn('00000000-0000-7000-8000-0000000000k1');
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        $ledger->method('post')->willReturnCallback(static function () use (&$posted): string {
            $posted = true;

            return 'journal';
        });
        $this->session($statements, $ledger)->drop('reg', 100, '2026-10-10');
        self::assertTrue($posted);
    }

    public function testTheClosingCountMustMatchTheTypedCash(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => 'sess']]);
        $session = $this->session($statements, $this->createStub(LedgerStore::class));
        try {
            $session->close('reg', [100 => 1], 500, 0, 0, true, '2026-10-01');
            self::fail('a mismatched closing count was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the bills and coins must add up to the counted cash', $error->getMessage());
        }
    }

    public function testADropCannotBeDatedBeforeTheOpen(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['id' => 'sess', 'opened_at' => '2026-10-10 12:00:00+00'],
        ]);
        $session = $this->session($statements, $this->createStub(LedgerStore::class));
        try {
            $session->drop('reg', 100, '2026-10-01');
            self::fail('a drop before the open was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('A cash drop cannot be dated before the register was opened.', $error->getMessage());
        }
    }

    public function testACloseWithoutARowIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'stall_register_expected_cash')) {
                return [['expected' => 0]];
            }
            if (str_contains($sql, 'closed_at IS NULL')) {
                return [['id' => 'sess']];
            }

            return [];
        });
        $statements->method('affected')->willReturn(0);
        $session = $this->session($statements, $this->createStub(LedgerStore::class));
        try {
            $session->close('reg', [], 0, 0, 0, true, '2026-10-01');
            self::fail('a missing close row was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the register is not open', $error->getMessage());
        }
    }

    public function testAnOveragePostsCashAndShort(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'stall_register_expected_cash')) {
                return [['expected' => 0]];
            }
            if (str_contains($sql, 'closed_at IS NULL')) {
                return [['id' => 'sess']];
            }

            return [];
        });
        $statements->method('affected')->willReturn(1);
        $posted = null;
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        $ledger->method('newId')->willReturn('00000000-0000-7000-8000-0000000000k1');
        $ledger->method('post')->willReturnCallback(static function (JournalDraft $draft) use (&$posted): string {
            $posted = $draft;
            if ($draft->beforeCommit !== null) {
                ($draft->beforeCommit)('00000000-0000-7000-8000-0000000000j1');
            }

            return '00000000-0000-7000-8000-0000000000j1';
        });
        $this->session($statements, $ledger)->close('reg', [], 100, 0, 0, true, '2026-10-01');
        self::assertInstanceOf(JournalDraft::class, $posted);
        self::assertSame(100, $posted->lines[0]['debit']);
    }

    public function testAVarianceWithoutAJournalIdIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'stall_register_expected_cash')) {
                return [['expected' => 100]];
            }
            if (str_contains($sql, 'closed_at IS NULL')) {
                return [['id' => 'sess']];
            }

            return [];
        });
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        $ledger->method('newId')->willReturn('00000000-0000-7000-8000-0000000000k1');
        $ledger->method('post')->willReturnCallback(static function (JournalDraft $draft): string {
            if ($draft->beforeCommit !== null) {
                ($draft->beforeCommit)('');
            }

            return '';
        });
        try {
            $this->session($statements, $ledger)->close('reg', [], 0, 0, 0, true, '2026-10-01');
            self::fail('a variance without a journal was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the register did not close', $error->getMessage());
        }
    }

    private function session(Statements $statements, LedgerStore $ledger): RegisterSession
    {
        $mall = (new ReflectionClass(MallSettings::class))->newInstanceWithoutConstructor();
        $rows = $this->mallRows();
        (new ReflectionProperty(MallSettings::class, 'statements'))->setValue($mall, $rows);
        (new ReflectionProperty(MallSettings::class, 'day'))->setValue($mall, new \PerfectConsign\Feature\Mall\MallDay($rows));
        $clock = new MallClock($mall);
        $activity = new RegisterActivity($statements, $clock);

        return new RegisterSession($statements, $ledger, $clock, $activity);
    }

    private function mallRows(): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([[
            'time_zone' => 'UTC',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 0,
            'floor_days' => 1,
            'cash_round_minor' => 0,
            'tax_bps' => 0,
            'use_color' => false,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => '',
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'receipt_footer' => '',
        ]]);

        return $statements;
    }
}
