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

final class VendorCheckCoverageTest extends TestCase
{
    public function testHomeStaysLastCheckAndClearedLines(): void
    {
        $vendor = '00000000-0000-7000-8000-0000000000a1';
        $run = '00000000-0000-7000-8000-0000000000c1';
        $checks = $this->checks($vendor, $run, true, 4000, true, 1200);
        $page = $checks->home($vendor, '2026-10-15');
        self::assertSame('Ada Lane', $page['name']);
        self::assertTrue($page['hold']);
        self::assertTrue($page['open']);
        self::assertSame('Rent from your share', $page['rent_label']);
        self::assertSame(1200, $page['rent']);
        self::assertSame(800, $page['check']);
        self::assertSame(4000, $page['desk']);
        self::assertNotNull($page['booth']);
        self::assertSame('A1', $page['booth']['code']);
        self::assertSame(2500, $page['booth']['rent']);
        self::assertSame("This month's rent is the days left.", $page['booth']['note']);
        self::assertCount(2, $page['others']);
        self::assertSame('B2', $page['others'][0]['code']);
        self::assertSame('', $page['others'][0]['ends_on']);
        self::assertNull($page['others'][0]['rent']);
        self::assertSame('The month you leave is a full month of rent.', $this->noteOnly($vendor, $run));
        self::assertNotNull($page['last']);
        self::assertSame($run, $page['last']['run_id']);
        self::assertSame('12', $page['last']['number']);
        self::assertSame('2026-10-02', $checks->lastPaidOn($vendor));
        $cleared = $checks->cleared($vendor, $run);
        self::assertNotNull($cleared);
        self::assertSame('2026-10-02', $cleared['paid_on']);
        self::assertSame(800, $cleared['amount']);
        self::assertSame('12', $cleared['number']);
        self::assertNotSame([], $cleared['lines']);
        $owed = $this->checks($vendor, $run, false, 500, false, 0);
        $open = $owed->home($vendor, '2026-10-15');
        self::assertSame('Rent you owe', $open['rent_label']);
        self::assertSame(500, $open['rent']);
        $idle = $this->checks($vendor, $run, false, 0, false, 900);
        $rent = $idle->home($vendor, '2026-10-15');
        self::assertSame(900, $rent['rent']);
        self::assertNull($idle->lastPaidOn('00000000-0000-7000-8000-0000000000aa'));
        self::assertNull($idle->cleared('00000000-0000-7000-8000-0000000000aa', $run));
    }

    private function noteOnly(string $vendor, string $run): string
    {
        $pdo = $this->createStub(PDO::class);
        $statements = $this->stayStatements($pdo, $vendor, $run, true, 0, false, 0, true);
        $checks = $this->fromStatements($pdo, $statements);

        return $checks->home($vendor, '2026-10-15')['booth']['note'] ?? '';
    }

    private function checks(
        string $vendor,
        string $run,
        bool $fromSales,
        int $owes,
        bool $hold,
        int $settled,
    ): VendorCheck {
        $pdo = $this->createStub(PDO::class);

        return $this->fromStatements(
            $pdo,
            $this->stayStatements($pdo, $vendor, $run, $fromSales, $owes, $hold, $settled, false)
        );
    }

    private function fromStatements(PDO $pdo, Statements $statements): VendorCheck
    {
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $links = new CatalogLinks($statements);
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines())
        );
        $take = new StoreTake();
        $account = new VendorAccount(
            $statements,
            new InventoryStore($statements, $links, $mall),
            new ItemForm(new IntakeCatalog($statements), new NameListStore($statements, $links), $mall, $take),
            $take
        );

        return new VendorCheck(
            $statements,
            new PayoutStore($pdo, $statements, $runs, new PayoutWindow()),
            $account
        );
    }

    private function stayStatements(
        PDO $pdo,
        string $vendor,
        string $run,
        bool $fromSales,
        int $owes,
        bool $hold,
        int $settled,
        bool $leaving,
    ): Statements {
        return new class ($pdo, $vendor, $run, $fromSales, $owes, $hold, $settled, $leaving) extends Statements {
            public function __construct(
                PDO $pdo,
                private readonly string $vendor,
                private readonly string $run,
                private readonly bool $fromSales,
                private readonly int $owes,
                private readonly bool $hold,
                private readonly int $settled,
                private readonly bool $leaving,
            ) {
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
                if (str_contains($sql, 'stall_payout_pack')) {
                    return [[
                        'payable' => 2000,
                        'rent' => 900,
                        'check_amount' => 800,
                        'owe_amount' => $this->owes,
                        'settled_minor' => $this->settled,
                        'rent_from_sales' => $this->fromSales,
                    ]];
                }
                if (str_contains($sql, 'AS total')) {
                    return [['total' => 0]];
                }
                if (str_contains($sql, 'payout_mode')) {
                    return [[
                        'name' => 'Ada Lane',
                        'payout_mode' => $this->hold ? 'hold' : 'pay',
                    ]];
                }
                if (str_contains($sql, 'assignment.starts_on')) {
                    if ($this->leaving) {
                        return [[
                            'code' => 'A1',
                            'starts_on' => '2026-09-01',
                            'ends_on' => '2026-10-20',
                            'amount_minor' => 2500,
                        ]];
                    }

                    return [
                        [
                            'code' => 'A1',
                            'starts_on' => '2026-10-08',
                            'ends_on' => null,
                            'amount_minor' => 2500,
                        ],
                        [
                            'code' => 'B2',
                            'starts_on' => '2026-08-01',
                            'ends_on' => null,
                            'amount_minor' => '',
                        ],
                        [
                            'code' => 'C3',
                            'starts_on' => '2026-07-01',
                            'ends_on' => '2026-07-31',
                            'amount_minor' => 1000,
                        ],
                    ];
                }
                if (str_contains($sql, 'vendor_statement')) {
                    return [[
                        'posted_on' => '2026-10-02',
                        'source_type' => 'check_disbursement',
                        'role' => 'vendor_payable',
                        'debit_minor' => 0,
                        'credit_minor' => 800,
                        'is_reversal' => false,
                        'run_id' => $this->run,
                    ]];
                }
                if (str_contains($sql, 'payout_run_lines AS line')) {
                    if (($params[0] ?? null) !== $this->vendor) {
                        return [];
                    }

                    return [[
                        'run_id' => $this->run,
                        'paid_on' => '2026-10-02',
                        'check_minor' => 800,
                        'check_number' => '12',
                    ]];
                }
                return [];
            }
        };
    }
}
