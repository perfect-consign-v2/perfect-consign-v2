<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PDO;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Vendor\VendorAccount;
use PerfectConsign\Feature\Vendor\VendorCheck;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class VendorCheckMutationTest extends TestCase
{
    private const VENDOR = '00000000-0000-7000-8000-0000000000a1';

    private const RUN = '00000000-0000-7000-8000-0000000000c1';

    public function testHoldAndDeskUseTheStrictBranches(): void
    {
        $held = $this->check('credit');
        $page = $held->home(self::VENDOR, '2026-10-15');
        self::assertTrue($page['hold']);
        self::assertSame(0, $page['desk']);
        $owed = $this->check('owed');
        self::assertSame(0, $owed->home(self::VENDOR, '2026-10-15')['desk']);
    }

    public function testTheCurrentBoothIsTheOneOpenToday(): void
    {
        self::assertSame('TODAY', $this->check('today')->home(self::VENDOR, '2026-10-15')['booth']['code'] ?? null);
        self::assertSame('ENDS', $this->check('ends')->home(self::VENDOR, '2026-10-15')['booth']['code'] ?? null);
        self::assertNull($this->check('future')->home(self::VENDOR, '2026-10-15')['booth']);
        self::assertSame('', $this->check('day1')->home(self::VENDOR, '2026-10-15')['booth']['note'] ?? null);
        self::assertSame('', $this->check('prior')->home(self::VENDOR, '2026-10-15')['booth']['note'] ?? null);
    }

    public function testActivityLabelsAndAmountsStayOnTheirBranches(): void
    {
        $page = $this->check('lines')->home(self::VENDOR, '2026-10-15');
        $labels = array_column($page['activity'], 'label');
        $amounts = array_column($page['activity'], 'amount');
        self::assertSame(['Undone', 'Bought at the store', 'Sale', 'Rent', 'Sale'], $labels);
        self::assertSame([0, 0, 150, 100, 300], $amounts);
        $cleared = $this->check('lines')->cleared(self::VENDOR, self::RUN);
        self::assertSame(['Late fee'], array_column($cleared['lines'] ?? [], 'label'));
    }

    private function check(string $scene): VendorCheck
    {
        $pdo = $this->createStub(PDO::class);
        $statements = $this->statements($pdo, $scene);
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $links = new CatalogLinks($statements);
        $mall = new MallSettings($statements, new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()), new \PerfectConsign\Feature\Mall\MallDay($statements));
        $take = new StoreTake();

        return new VendorCheck(
            $statements,
            new PayoutStore($pdo, $statements, $runs, new PayoutWindow()),
            new VendorAccount(
                $statements,
                new \PerfectConsign\Feature\Inventory\ItemQueries($statements),
                new ItemForm(new IntakeCatalog($statements), new NameListStore($statements, $links), $mall, $take),
                $take
            )
        );
    }

    private function statements(PDO $pdo, string $scene): Statements
    {
        return new class ($pdo, $scene) extends Statements {
            public function __construct(PDO $pdo, private readonly string $scene)
            {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [['rent_cycle_day' => 1]];
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, "status = 'draft'")) {
                    return [];
                }
                if (
                    str_contains($sql, 'stall_payout_pack')
                    || str_contains($sql, 'stall_payout_quote')
                    || str_contains($sql, 'stall_payout_run_quote')
                ) {
                    if ($this->scene !== 'owed') {
                        return [];
                    }

                    return [[
                        'payable' => 0,
                        'rent' => 0,
                        'check_amount' => 0,
                        'owe_amount' => 500,
                        'settled_minor' => 0,
                        'rent_from_sales' => false,
                    ]];
                }
                if (str_contains($sql, 'AS total')) {
                    $rent = str_contains($sql, 'booth_rent_receivable') && $this->scene === 'credit' ? -40 : 0;

                    return [['total' => $rent]];
                }
                if (str_contains($sql, 'payout_mode')) {
                    return [[
                        'name' => 'Ada',
                        'payout_mode' => $this->scene === 'credit' ? 'credit_only' : 'pay',
                    ]];
                }
                if (str_contains($sql, 'assignment.starts_on')) {
                    return $this->stays();
                }
                if (str_contains($sql, 'payout_run_journals')) {
                    return [$this->line('late_fee', 'vendor_payable', 0, 100, false)];
                }
                if (str_contains($sql, 'vendor_statement')) {
                    return [
                        $this->line('check_disbursement', 'vendor_payable', 0, 0, true),
                        $this->line('sale', 'vendor_payable', 40, 40, false),
                        $this->line('sale', 'vendor_payable', 0, 150, false),
                        $this->line('rent_charge', 'vendor_payable', 40, 140, false),
                        $this->line('sale', 'booth_rent_receivable', 500, 200, false),
                    ];
                }
                if (str_contains($sql, 'payout_run_lines')) {
                    return [[
                        'run_id' => '00000000-0000-7000-8000-0000000000c1',
                        'paid_on' => '2026-10-02',
                        'check_minor' => 100,
                        'check_number' => '9',
                    ]];
                }

                return [];
            }

            /**
             * @return list<array<string, mixed>>
             */
            private function stays(): array
            {
                $row = match ($this->scene) {
                    'today' => ['TODAY', '2026-10-15', null],
                    'ends' => ['ENDS', '2026-09-01', '2026-10-15'],
                    'future' => ['FUTURE', '2026-10-16', null],
                    'day1' => ['DAY1', '2026-10-01', null],
                    'prior' => ['PRIOR', '2026-09-15', null],
                    default => ['NONE', '2026-01-01', '2026-01-02'],
                };

                return [[
                    'code' => $row[0],
                    'starts_on' => $row[1],
                    'ends_on' => $row[2],
                    'amount_minor' => 100,
                ]];
            }

            /**
             * @return array<string, mixed>
             */
            private function line(string $source, string $role, int $debit, int $credit, bool $reversal): array
            {
                return [
                    'posted_on' => '2026-10-02',
                    'source_type' => $source,
                    'role' => $role,
                    'debit_minor' => $debit,
                    'credit_minor' => $credit,
                    'is_reversal' => $reversal,
                    'run_id' => null,
                ];
            }
        };
    }
}
