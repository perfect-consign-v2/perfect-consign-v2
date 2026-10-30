<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Register;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RegisterStoreTest extends TestCase
{
    public function testAMissingRegisterCannotBeFoundOrRenamed(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $statements->method('affected')->willReturn(0);
        $store = $this->store($statements);
        self::assertNull($store->find('missing'));
        try {
            $store->rename('missing', 'Front');
            self::fail('a missing register was renamed');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that register is not in this mall', $error->getMessage());
        }
    }

    public function testTheLastRegisterAndOneWithShiftsStay(): void
    {
        $last = $this->createStub(Statements::class);
        $last->method('bound')->willReturn([['registers' => 1, 'shifts' => 0]]);
        try {
            $this->store($last)->remove('reg-1');
            self::fail('the last register was deleted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('The last register cannot be deleted.', $error->getMessage());
        }
        $used = $this->createStub(Statements::class);
        $used->method('bound')->willReturn([['registers' => 2, 'shifts' => 1]]);
        try {
            $this->store($used)->remove('reg-1');
            self::fail('a used register was deleted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('A register that has been opened cannot be deleted.', $error->getMessage());
        }
        $gone = $this->createStub(Statements::class);
        $gone->method('bound')->willReturnOnConsecutiveCalls([], [['registers' => 2, 'shifts' => 0]]);
        try {
            $this->store($gone)->remove('reg-1');
            self::fail('a missing register was deleted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that register is not in this mall', $error->getMessage());
        }
        $race = $this->createStub(Statements::class);
        $race->method('bound')->willReturn([['registers' => 2, 'shifts' => 0]]);
        $race->method('affected')->willReturn(0);
        try {
            $this->store($race)->remove('reg-1');
            self::fail('a raced register was deleted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that register is not in this mall', $error->getMessage());
        }
    }

    public function testOpenSessionPicksOneDrawerOrAsks(): void
    {
        $none = $this->createStub(Statements::class);
        $none->method('rows')->willReturn([]);
        try {
            $this->store($none)->openSession('');
            self::fail('an empty mall opened a drawer');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a drawer journal needs an open register', $error->getMessage());
        }
        $one = $this->createStub(Statements::class);
        $one->method('rows')->willReturn([['id' => 's1', 'name' => 'Front']]);
        self::assertSame('s1', $this->store($one)->openSession(''));
        self::assertSame('s1', $this->store($one)->openSession('s1'));
        try {
            $this->store($one)->openSession('other');
            self::fail('a closed drawer was used');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a drawer journal needs an open register', $error->getMessage());
        }
        $two = $this->createStub(Statements::class);
        $two->method('rows')->willReturn([['id' => 's1', 'name' => 'Front'], ['id' => 's2', 'name' => 'Back']]);
        try {
            $this->store($two)->openSession('');
            self::fail('two drawers were chosen at once');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose the active register.', $error->getMessage());
        }
    }

    public function testStoredValueNumbersAreChecked(): void
    {
        $store = $this->store($this->createStub(Statements::class));
        try {
            $store->storedValueId('gift_card', ' ');
            self::fail('a blank card was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a stored value number is required', $error->getMessage());
        }
        $missing = $this->createStub(Statements::class);
        $missing->method('bound')->willReturn([]);
        try {
            $this->store($missing)->storedValueId('gift_certificate', 'GC-1');
            self::fail('a missing certificate was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame(RegisterStore::GIFT_CERTIFICATE_MISSING, $error->getMessage());
        }
        try {
            $this->store($missing)->storedValueId('gift_card', 'GC-1');
            self::fail('a missing card was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that gift card number is not on file', $error->getMessage());
        }
        $found = $this->createStub(Statements::class);
        $found->method('bound')->willReturn([['id' => 'sv-1']]);
        self::assertSame('sv-1', $this->store($found)->storedValueId('gift_card', 'GC-1'));
    }

    public function testARegisterNameIsRequired(): void
    {
        $store = $this->store($this->createStub(Statements::class));
        try {
            $store->add(' Front');
            self::fail('a padded name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a register name is required and at most 40 characters', $error->getMessage());
        }
    }

    public function testCardsFillAnOpenDrawer(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'time_zone')) {
                return [['time_zone' => 'UTC']];
            }

            return [
                [
                    'id' => 'reg-1',
                    'name' => 'Front',
                    'shift_count' => 2,
                    'session_id' => 'sess-1',
                    'cashier' => 'Ada',
                    'opened_at' => '2026-10-01 09:00:00',
                    'opening_cash_minor' => 100,
                    'open_ticket' => 1,
                ],
                [
                    'id' => 'reg-2',
                    'name' => 'Back',
                    'shift_count' => 0,
                    'session_id' => null,
                    'cashier' => null,
                    'opened_at' => null,
                    'opening_cash_minor' => 0,
                    'open_ticket' => 0,
                ],
            ];
        });
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'stall_register_expected_cash')) {
                return [['expected' => 250]];
            }
            if (str_contains($sql, 'price_minor * quantity + tax_minor')) {
                return [['total' => 3083]];
            }

            return [];
        });
        $cards = $this->store($statements)->cards();
        self::assertTrue($cards[0]['open']);
        self::assertTrue($cards[0]['open_ticket']);
        self::assertSame('$30.83', $cards[0]['unsynced_cash']);
        self::assertSame('$30.83', $cards[0]['refused_cash']);
        self::assertFalse($cards[1]['open']);
        self::assertSame('', $cards[1]['cashier']);
    }

    public function testOfflineSnapTotalsUseUnitPriceTimesQuantityPlusTax(): void
    {
        $seen = [];
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->exactly(2))
            ->method('bound')
            ->willReturnCallback(static function (string $sql, array $params) use (&$seen): array {
                $seen[] = ['sql' => $sql, 'params' => $params];
                self::assertStringContainsString('price_minor * quantity + tax_minor', $sql);
                self::assertStringNotContainsString('sum(price_minor),', $sql);

                return [['total' => $params[1] === 'waiting' ? 3083 : 0]];
            });
        $method = (new ReflectionClass(RegisterStore::class))->getMethod('snapTotal');
        $store = $this->store($statements);
        self::assertSame(3083, $method->invoke($store, '01900000-0000-7000-8000-000000000001', 'waiting'));
        self::assertSame(0, $method->invoke($store, '01900000-0000-7000-8000-000000000001', 'refused'));
        self::assertSame(
            ['waiting', 'refused'],
            array_map(static fn (array $call): string => (string) $call['params'][1], $seen)
        );
    }

    public function testSaleIsADrawerSource(): void
    {
        self::assertTrue($this->store($this->createStub(Statements::class))->drawerSource('sale'));
        self::assertFalse($this->store($this->createStub(Statements::class))->drawerSource('opening'));
    }

    public function testZTapeExpectedCashLabelsLayawayDepositWithoutUnderscore(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['source_type' => 'layaway_deposit', 'net' => 2500],
            ['source_type' => 'layaway_refund', 'net' => -500],
        ]);
        $activity = $this->activity($statements);
        $method = (new ReflectionClass(RegisterActivity::class))->getMethod('cashLines');
        /** @var list<array{label: string, amount: string}> $lines */
        $lines = $method->invoke($activity, 'sess-1', 10000);
        self::assertSame(
            [
                ['label' => 'Opening count', 'amount' => '$100.00'],
                ['label' => 'Layaway deposit', 'amount' => '$25.00'],
                ['label' => 'Layaway refund', 'amount' => '-$5.00'],
            ],
            $lines
        );
    }

    private function store(Statements $statements): RegisterStore
    {
        $mall = new MallSettings(
            $statements,
            (new ReflectionClass(\PerfectConsign\Feature\Company\CompanyStore::class))->newInstanceWithoutConstructor(),
            new \PerfectConsign\Feature\Mall\MallDay($statements),
        );
        $clock = new MallClock($mall);
        $activity = new RegisterActivity($statements, $clock);

        return new RegisterStore($statements, $clock, $activity);
    }

    private function activity(Statements $statements): RegisterActivity
    {
        $mall = new MallSettings(
            $statements,
            (new ReflectionClass(\PerfectConsign\Feature\Company\CompanyStore::class))->newInstanceWithoutConstructor(),
            new \PerfectConsign\Feature\Mall\MallDay($statements),
        );

        return new RegisterActivity($statements, new MallClock($mall));
    }
}
