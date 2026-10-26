<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PDO;
use PerfectConsign\Feature\Payout\CheckWords;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutOnce;
use PerfectConsign\Feature\Payout\PayoutRunChecks;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunSheets;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\RentShortfall;
use PerfectConsign\Feature\Rent\RentCycle;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class PayoutMutationTest extends TestCase
{
    public function testAPreviewSheetNeedsEveryDate(): void
    {
        $sheets = new PayoutRunSheets($this->previewSheets());
        $vendor = '00000000-0000-7000-8000-0000000000a1';
        $open = $sheets->envelopes('not-a-run', '2026-10-01', '2026-10-31', '2026-10-01', '2026-10-18');
        self::assertSame($vendor, $open[0]['vendor_id'] ?? null);
        self::assertSame([], $sheets->envelopes('not-a-run', '2026-10-1', '2026-10-31', '2026-10-01', '2026-10-18'));
        self::assertSame([], $sheets->envelopes('not-a-run', '2026-10-01', 'October', '2026-10-01', '2026-10-18'));
        self::assertSame([], $sheets->envelopes('not-a-run', '2026-10-01', '2026-10-31', '10-01', '2026-10-18'));
        self::assertSame([], $sheets->envelopes('not-a-run', '2026-10-01', '2026-10-31', '2026-10-01', '18'));
    }

    public function testKnownTendersStayAndABlankBoothIdIsEmpty(): void
    {
        foreach (['drawer_cash', 'safe_cash', 'check'] as $tender) {
            $statements = $this->draftStatements();
            $store = new PayoutRunStore($statements, new OccupiedPack($statements));
            $store->ensureDraft('2026-10-01', '2026-10-31', '2026-10-01', '2026-10-18', $tender);
            self::assertSame($tender, $statements->seenTender);
        }
        $wire = $this->draftStatements();
        $store = new PayoutRunStore($wire, new OccupiedPack($wire));
        $store->ensureDraft('2026-10-01', '2026-10-31', '2026-10-01', '2026-10-18', 'wire');
        self::assertSame('check', $wire->seenTender);
        $pack = $store->preview('2026-10-01', '2026-10-31', '2026-10-01', '2026-10-18');
        self::assertSame('', $pack[0]['boothId'] ?? null);
    }

    public function testAZeroDisbursementIsNotPosted(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('post')->willReturn('jid');
        $statements = $this->createStub(Statements::class);
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $pay = new PayoutDisburser(
            $ledger,
            new PayoutStore($this->createStub(PDO::class), $statements, $runs, new PayoutWindow()),
            $runs,
            new PayoutRunLines($statements),
            new PayoutRunChecks($statements),
            QuietAudit::make($statements),
        );
        self::assertSame('', $pay->disburse('run', 'vendor', '2026-10-18', 0, null));
        self::assertSame('', $pay->disburse('run', 'vendor', '2026-10-18', -5, null));
    }

    public function testSalesFlagsAndDebtorCentsStayExact(): void
    {
        foreach ([true, 1, '1', 't', 'true'] as $flag) {
            self::assertTrue($this->quote($flag)['rent_from_sales'] ?? false);
        }
        self::assertFalse($this->quote('f')['rent_from_sales'] ?? true);
        $open = $this->debtors(null, 1);
        self::assertSame('PREVIEW', $open[0]['code'] ?? null);
        self::assertSame('1.05', $open[0]['collect'] ?? null);
        $saved = $this->debtors('00000000-0000-7000-8000-000000000020', 1);
        self::assertSame('SAVED', $saved[0]['code'] ?? null);
        $window = $this->debtorWindow(28);
        self::assertSame((new RentCycle(28))->payout('2026-10-18')['from'], $window);
    }

    public function testASettledRunMatchesAndAZeroCheckIsSkipped(): void
    {
        $settled = '00000000-0000-7000-8000-000000000021';
        $draft = '00000000-0000-7000-8000-000000000022';
        $history = $this->historyStatements([
            $this->payoutRun($draft, 'draft', '2026-10-01'),
            $this->payoutRun($settled, 'settled', '2026-10-01'),
            $this->payoutRun('00000000-0000-7000-8000-000000000023', 'paid', 'not-a-day'),
        ]);
        $once = $this->payoutOnce($history, $this->checkSheets(0, '100'));
        $found = $once->finishedRun('2026-10-01', '2026-10-31', '2026-10-01');
        self::assertSame($settled, $found['id'] ?? null);
        self::assertNull($once->finishedRun('not-a-day', '2026-10-31', '2026-10-01'));
        self::assertSame([], $once->savedChecks('00000000-0000-7000-8000-000000000020'));
    }

    public function testShortfallMathUsesOnlyARealMonth(): void
    {
        $shortfall = new RentShortfall($this->chargeStatements());
        $row = $this->packRow(70, 0, 0);
        $noted = $shortfall->attach([$row], '2026-10-01');
        self::assertStringContainsString('B-2', $noted[0]['note'] ?? '');
        $ignored = $shortfall->attach([$row], '2026-10');
        self::assertSame('Still unpaid from an earlier month: $0.70.', $ignored[0]['note'] ?? null);
        $gap = $shortfall->attach([$this->packRow(100, 40, 10)], 'nope');
        self::assertSame('Still unpaid from an earlier month: $0.70.', $gap[0]['note'] ?? null);
        $even = $shortfall->attach([$this->packRow(0, 0, 0)], '2026-10-01');
        self::assertSame('', $even[0]['note'] ?? null);
    }

    private function previewSheets(): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (
                    !str_contains($sql, 'stall_payout_pack')
                    && !str_contains($sql, 'stall_payout_quote')
                    && !str_contains($sql, 'stall_payout_run_quote')
                ) {
                    return [];
                }

                return [[
                    'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                    'code' => 'ADA',
                    'name' => 'Ada',
                    'booth' => 'A-1',
                    'sold_on' => '2026-10-02',
                    'item_code' => 'hat',
                    'description' => 'Hat',
                    'price_minor' => 500,
                    'quantity' => 1,
                    'commission_minor' => 100,
                    'settled_minor' => 0,
                    'check_minor' => 400,
                    'owes_minor' => 0,
                    'check_number' => '',
                ]];
            }
        };
    }

    private function draftStatements(): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo) extends Statements {
            public string $seenTender = '';

            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, 'INSERT INTO payout_runs')) {
                    $this->seenTender = is_string($params[3] ?? null) ? $params[3] : '';

                    return [['id' => '00000000-0000-7000-8000-000000000020']];
                }
                if (
                    str_contains($sql, 'stall_payout_pack')
                    || str_contains($sql, 'stall_payout_quote')
                    || str_contains($sql, 'stall_payout_run_quote')
                ) {
                    return [[
                        'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                        'code' => 'ADA',
                        'name' => 'Ada',
                        'booth' => '',
                        'booth_id' => null,
                        'mode' => 'pay',
                        'prior' => 0,
                        'charged' => 0,
                        'paid' => 0,
                        'payable' => 0,
                        'rent' => 0,
                        'month_rent' => 0,
                        'check_amount' => 0,
                        'owe_amount' => 0,
                        'settled_minor' => 0,
                        'adjustment_minor' => 0,
                    ]];
                }

                return [];
            }
        };
    }

    /**
     * @return array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}
     */
    private function quote(mixed $flag): array
    {
        $store = new PayoutStore(
            $this->createStub(PDO::class),
            $this->mallStatements(null, 1, $flag, 0),
            new PayoutRunStore($this->mallStatements(null, 1, $flag, 0), new OccupiedPack($this->createStub(Statements::class))),
            new PayoutWindow(),
        );
        $quoted = $store->quote('00000000-0000-7000-8000-0000000000a1', '2026-10-18');
        self::assertNotNull($quoted);

        return $quoted;
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth_id: string, booth: string, owes: int, collect: string}>
     */
    private function debtors(?string $runId, int $cycleDay): array
    {
        $statements = $this->mallStatements($runId, $cycleDay, false, 105);
        $store = new PayoutStore(
            $this->createStub(PDO::class),
            $statements,
            new PayoutRunStore($statements, new OccupiedPack($statements)),
            new PayoutWindow(),
        );

        return $store->debtors('2026-10-18');
    }

    private function debtorWindow(int $cycleDay): string
    {
        $statements = $this->mallStatements(null, $cycleDay, false, 105);
        $store = new PayoutStore(
            $this->createStub(PDO::class),
            $statements,
            new PayoutRunStore($statements, new OccupiedPack($statements)),
            new PayoutWindow(),
        );
        $store->debtors('2026-10-18');

        return $statements->windowFrom;
    }

    private function mallStatements(?string $runId, int $cycleDay, mixed $flag, int $owes): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo, $runId, $cycleDay, $flag, $owes) extends Statements {
            public string $windowFrom = '';

            public function __construct(
                PDO $pdo,
                private readonly ?string $runId,
                private readonly int $cycleDay,
                private readonly mixed $flag,
                private readonly int $owes,
            ) {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [['rent_cycle_day' => $this->cycleDay]];
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, "status = 'draft'")) {
                    return $this->runId === null ? [] : [['id' => $this->runId]];
                }
                if (
                    str_contains($sql, 'stall_payout_pack')
                    || str_contains($sql, 'stall_payout_quote')
                    || str_contains($sql, 'stall_payout_run_quote')
                ) {
                    $from = str_contains($sql, 'stall_payout_quote')
                        ? ($params[1] ?? null)
                        : ($params[0] ?? null);
                    $this->windowFrom = is_string($from) ? $from : '';
                    if (str_contains($sql, 'pack.owes')) {
                        return [$this->debtor('PREVIEW')];
                    }

                    return [[
                        'payable' => 0,
                        'rent' => 0,
                        'check_amount' => 0,
                        'owe_amount' => 0,
                        'settled_minor' => 0,
                        'rent_from_sales' => $this->flag,
                    ]];
                }
                if (str_contains($sql, 'FROM payout_pack')) {
                    return [$this->debtor('SAVED')];
                }

                return [];
            }

            /**
             * @return array<string, mixed>
             */
            private function debtor(string $code): array
            {
                return [
                    'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                    'code' => $code,
                    'name' => 'Ada',
                    'booth' => 'A-1',
                    'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                    'owes' => $this->owes,
                ];
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $runs
     */
    private function historyStatements(array $runs): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo, $runs) extends Statements {
            /**
             * @param list<array<string, mixed>> $runs
             */
            public function __construct(PDO $pdo, private readonly array $runs)
            {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return $this->runs;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutRun(string $id, string $status, string $from): array
    {
        return [
            'status' => $status,
            'id' => $id,
            'tender' => 'check',
            'sales_from' => $from,
            'sales_to' => '2026-10-31',
            'rent_month' => '2026-10-01',
        ];
    }

    private function checkSheets(int $check, string $number): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo, $check, $number) extends Statements {
            public function __construct(PDO $pdo, private readonly int $check, private readonly string $number)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                return [[
                    'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                    'code' => 'ADA',
                    'name' => 'Ada',
                    'booth' => 'A-1',
                    'sold_on' => '',
                    'item_code' => '',
                    'description' => '',
                    'price_minor' => 0,
                    'quantity' => 0,
                    'commission_minor' => 0,
                    'settled_minor' => 0,
                    'check_minor' => $this->check,
                    'owes_minor' => 0,
                    'check_number' => $this->number,
                ]];
            }
        };
    }

    private function payoutOnce(Statements $runs, Statements $sheets): PayoutOnce
    {
        $store = new PayoutRunStore($runs, new OccupiedPack($runs));

        return new PayoutOnce(
            $store,
            new PayoutRunSheets($sheets),
            new PayoutSettler(
                $this->createStub(LedgerStore::class),
                new PayoutStore($this->createStub(PDO::class), $runs, $store, new PayoutWindow()),
                $store,
                new PayoutRunLines($runs),
                QuietAudit::make($runs),
                (new \ReflectionClass(\PerfectConsign\Feature\Payout\VendorClawback::class))->newInstanceWithoutConstructor(),
            ),
            new PayoutDisburser(
                $this->createStub(LedgerStore::class),
                new PayoutStore($this->createStub(PDO::class), $runs, $store, new PayoutWindow()),
                $store,
                new PayoutRunLines($runs),
                new PayoutRunChecks($runs),
                QuietAudit::make($runs),
            ),
            $runs,
            new CheckWords(),
            new RentShortfall($runs),
            $this->createStub(\PerfectConsign\Bootstrap\SaleCeiling::class),
        );
    }

    private function chargeStatements(): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (!str_contains($sql, 'rent_charge')) {
                    return [];
                }

                return [[
                    'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                    'booth' => 'B-2',
                    'posted' => '2026-09-01',
                    'starts_on' => '2026-08-01',
                    'monthly' => 7000,
                    'charged' => 70,
                ]];
            }
        };
    }

    /**
     * @return array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int}
     */
    private function packRow(int $owes, int $monthRent, int $payable): array
    {
        return [
            'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
            'code' => 'ADA',
            'name' => 'Ada',
            'booth' => 'B-2',
            'boothId' => '',
            'mode' => 'pay',
            'prior' => 0,
            'charged' => 0,
            'paid' => 0,
            'payable' => $payable,
            'rent' => 0,
            'monthRent' => $monthRent,
            'check' => 0,
            'owes' => $owes,
            'settled' => 0,
            'adjustment' => 0,
        ];
    }
}
