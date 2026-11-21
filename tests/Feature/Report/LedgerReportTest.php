<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\LedgerReport;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class LedgerReportTest extends TestCase
{
    public function testEachSheetKeepsTheColumnsTheBooksReturn(): void
    {
        $report = new LedgerReport($this->statements(static function (string $sql): array {
            if (str_contains($sql, 'stall_report_sales')) {
                return [[
                    'label' => 'Lane',
                    'merchandise_minor' => 1200,
                    'tax_minor' => '80',
                    'receipt_count' => 2,
                    'taken_minor' => 0,
                ]];
            }
            if (str_contains($sql, 'stall_report_rent')) {
                return [['booth' => 'A1', 'vendor' => 'Ada', 'open_minor' => 500]];
            }
            if (str_contains($sql, 'stall_report_payouts')) {
                return [[
                    'paid_on' => '2026-06-15',
                    'vendor' => 'Ada',
                    'amount_minor' => 400,
                    'source_type' => 'check_disbursement',
                ]];
            }
            if (str_contains($sql, 'stall_report_stored_value')) {
                return [['kind' => 'gift_card', 'balance_minor' => 1500]];
            }
            if (str_contains($sql, 'stall_report_expiring')) {
                return [[
                    'code' => 't1',
                    'description' => 'Coat',
                    'vendor' => 'Ada',
                    'expires_on' => '2026-07-01',
                    'days_left' => 11,
                    'quantity' => 1,
                    'price_minor' => 800,
                ]];
            }
            if (str_contains($sql, 'stall_report_donated')) {
                return [[
                    'posted_on' => '2026-06-02',
                    'code' => 't2',
                    'description' => 'Hat',
                    'vendor_name' => null,
                    'quantity' => null,
                ]];
            }

            return [[
                'category' => 'Coats',
                'sold_qty' => 3,
                'on_hand_qty' => 4,
                'sales_minor' => 900,
                'sold_percent' => 43,
            ]];
        }));

        self::assertSame([
            [
                'label' => 'Lane',
                'merchandise_minor' => 1200,
                'tax_minor' => 80,
                'receipt_count' => 2,
                'taken_minor' => 0,
            ],
        ], $report->sales('2026-06-01', '2026-06-30', 'vendor'));
        self::assertSame([
            ['booth' => 'A1', 'vendor' => 'Ada', 'open_minor' => 500],
        ], $report->rent());
        self::assertSame([
            [
                'paid_on' => '2026-06-15',
                'vendor' => 'Ada',
                'amount_minor' => 400,
                'source_type' => 'check_disbursement',
            ],
        ], $report->payouts('2026-06-01', '2026-06-30'));
        self::assertSame([
            ['kind' => 'gift_card', 'balance_minor' => 1500],
        ], $report->storedValue());
        self::assertSame([
            [
                'code' => 't1',
                'description' => 'Coat',
                'vendor' => 'Ada',
                'expires_on' => '2026-07-01',
                'days_left' => 11,
                'quantity' => 1,
                'price_minor' => 800,
            ],
        ], $report->expiring());
        self::assertSame([
            [
                'posted_on' => '2026-06-02',
                'code' => 't2',
                'description' => 'Hat',
                'vendor_name' => null,
                'quantity' => null,
            ],
        ], $report->donated('2026-06-01', '2026-06-30'));
        self::assertSame([
            [
                'category' => 'Coats',
                'sold_qty' => 3,
                'on_hand_qty' => 4,
                'sales_minor' => 900,
                'sold_percent' => 43,
            ],
        ], $report->sellThrough('2026-06-01', '2026-06-30'));
    }

    public function testAnEmptySheetStaysEmpty(): void
    {
        $report = new LedgerReport($this->statements(static fn (string $sql): array => []));

        self::assertSame([], $report->sales('2026-06-01', '2026-06-30', 'day'));
        self::assertSame([], $report->rent());
        self::assertSame([], $report->payouts('2026-06-01', '2026-06-30'));
        self::assertSame([], $report->storedValue());
        self::assertSame([], $report->expiring());
        self::assertSame([], $report->donated('2026-06-01', '2026-06-30'));
        self::assertSame([], $report->sellThrough('2026-06-01', '2026-06-30'));
    }

    private function statements(callable $rows): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(
            static function (string $sql, array $params) use ($rows): array {
                self::assertNotSame([], $params);

                return $rows($sql);
            }
        );
        $statements->method('rows')->willReturnCallback(
            static function (string $sql) use ($rows): array {
                return $rows($sql);
            }
        );

        return $statements;
    }
}
