<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PDO;
use PDOException;
use PerfectConsign\Feature\Payout\CheckWords;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutOnce;
use PerfectConsign\Feature\Payout\PayoutReverser;
use PerfectConsign\Feature\Payout\PayoutRunChecks;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunSheets;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\RentShortfall;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class PayoutCoverageTest extends TestCase
{
    public function testSheetsNeedARunOrAWindow(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $sheets = new PayoutRunSheets($statements);
        self::assertSame([], $sheets->envelopes('00000000-0000-7000-8000-000000000099'));
        self::assertSame([], $sheets->envelopes('not-a-run', '2026-10-01', '', '2026-10-01', '2026-10-01'));
        self::assertSame([], $sheets->open('00000000-0000-7000-8000-000000000099'));
    }

    public function testACheckIsRefusedUntilTheRunIsSettled(): void
    {
        $draft = $this->runStatements([[
            'tender' => 'check',
            'status' => 'draft',
            'posting_date' => '2026-10-01',
            'rent_month' => '2026-10-01',
        ]]);
        $pay = new PayoutDisburser(
            $this->createStub(LedgerStore::class),
            $this->payouts($draft, '50'),
            new PayoutRunStore($draft, new OccupiedPack($draft)),
            new PayoutRunLines($draft),
            new PayoutRunChecks($draft),
        );
        try {
            $pay->pay('00000000-0000-7000-8000-000000000020', '2026-10-01');
            self::fail('a draft run was paid');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a check is written only while the run is settled', $error->getMessage());
        }
        try {
            $pay->numberChecks('00000000-0000-7000-8000-000000000020', 100);
            self::fail('draft checks were numbered');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Those checks are not ready to print.', $error->getMessage());
        }
        self::assertSame('', $pay->disburse(
            '00000000-0000-7000-8000-000000000020',
            '00000000-0000-7000-8000-0000000000a1',
            '2026-10-01',
            0,
            null
        ));
        $settled = $this->runStatements(
            [[
                'tender' => 'check',
                'status' => 'settled',
                'posting_date' => '2026-10-01',
                'rent_month' => '2026-10-01',
            ]],
            [[
                'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                'check_minor' => 1000,
                'mode' => 'pay',
            ]]
        );
        $changed = new PayoutDisburser(
            $this->createStub(LedgerStore::class),
            $this->payouts($settled, '50'),
            new PayoutRunStore($settled, new OccupiedPack($settled)),
            new PayoutRunLines($settled),
            new PayoutRunChecks($settled),
        );
        try {
            $changed->pay('00000000-0000-7000-8000-000000000020', '2026-10-01');
            self::fail('a changed payable was paid');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the open payable changed after review', $error->getMessage());
        }
    }

    public function testAMissingPayoutJournalCannotBeReversed(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn(null);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('that payout journal is not in this mall');
        (new PayoutReverser($ledger, $this->createStub(Statements::class)))->reverse(
            '00000000-0000-7000-8000-000000000099',
            '2026-10-01'
        );
    }

    public function testASettlementWithNothingAppliedIsEmpty(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $settler = new PayoutSettler(
            $this->createStub(LedgerStore::class),
            $this->payouts($statements, '0'),
            new PayoutRunStore($statements, new OccupiedPack($statements)),
            new PayoutRunLines($statements),
        );
        self::assertSame('', $settler->settle('run', 'vendor', '2026-10-01', 0));
        self::assertSame([], $settler->chargeMissing('run', 'vendor'));
    }

    public function testAFinishedRunMatchesTheWindow(): void
    {
        $history = $this->runStatements([], [], [
            ['status' => 'draft'],
            [
                'status' => 'paid',
                'id' => '00000000-0000-7000-8000-000000000021',
                'tender' => 'check',
                'sales_from' => '2026-10-01',
                'sales_to' => '2026-10-31',
                'rent_month' => '2026-10-01',
            ],
        ]);
        $found = $this->payoutOnce($history, $history)->finishedRun('2026-10-01', '2026-10-31', '2026-10-01');
        self::assertSame('00000000-0000-7000-8000-000000000021', $found['id'] ?? null);
        $missed = $this->payoutOnce($this->runStatements([], [], [
            ['status' => 1],
            [
                'status' => 'paid',
                'id' => '',
                'sales_from' => '2026-10-01',
                'sales_to' => '2026-10-31',
                'rent_month' => '2026-10-01',
            ],
            [
                'status' => 'paid',
                'id' => 12,
                'sales_from' => '2026-10-01',
                'sales_to' => '2026-10-31',
                'rent_month' => '2026-10-01',
            ],
            [
                'status' => 'settled',
                'id' => '00000000-0000-7000-8000-000000000022',
                'tender' => 4,
                'sales_from' => '2026-09-01',
                'sales_to' => '2026-09-30',
                'rent_month' => '2026-09-01',
            ],
            [
                'status' => 'paid',
                'id' => '00000000-0000-7000-8000-000000000023',
                'tender' => 'check',
                'sales_from' => 'not-a-day',
                'sales_to' => '2026-10-31',
                'rent_month' => '2026-10-01',
            ],
        ]), $history)->finishedRun('2026-10-01', '2026-10-31', '2026-10-01');
        self::assertNull($missed);
    }

    public function testSavedChecksSkipBlankAndDuplicateVendors(): void
    {
        $vendor = '00000000-0000-7000-8000-0000000000a1';
        $sheets = $this->runStatements([], [
            $this->sheet($vendor, 0, ''),
            $this->sheet('00000000-0000-7000-8000-0000000000a2', 1500, ''),
            $this->sheet($vendor, 2500, '1042'),
            $this->sheet($vendor, 2500, '1043'),
        ]);
        $once = $this->payoutOnce($this->runStatements([]), $sheets);
        self::assertSame([], $once->envelopes('not-a-run'));
        $checks = $once->savedChecks('00000000-0000-7000-8000-000000000020');
        self::assertSame('1042', $checks[0]['number'] ?? null);
        self::assertSame(2500, $checks[0]['amount'] ?? null);
        self::assertCount(1, $checks);
    }

    public function testOpenPackUsesADraftWhenOneExists(): void
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class ($pdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, "status = 'draft'")) {
                    return [['id' => '00000000-0000-7000-8000-000000000020']];
                }
                if (!str_contains($sql, 'mode')) {
                    return [];
                }

                return [[
                    'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                    'code' => 'ADA',
                    'name' => 'Ada Lane',
                    'booth' => 'A-1',
                    'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                    'mode' => 'pay',
                    'prior' => 0,
                    'charged' => 0,
                    'paid' => 0,
                    'payable' => 2500,
                    'rent' => 0,
                    'month_rent' => 0,
                    'check_amount' => 2500,
                    'owe_amount' => 0,
                    'settled_minor' => 0,
                    'adjustment_minor' => 0,
                ]];
            }

            public function rows(string $sql): array
            {
                return [];
            }
        };
        $once = $this->payoutOnce($statements, $statements);
        $pack = $once->openPack('2026-10-01', '2026-10-31', '2026-10-01', '2026-10-17');
        self::assertSame('pay', $pack[0]['mode'] ?? null);
        $saved = $once->savedPack('00000000-0000-7000-8000-000000000020');
        self::assertSame('ADA', $saved[0]['code'] ?? null);
        $preview = $this->payoutOnce($this->runStatements([]), $this->runStatements([]))->openPack(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            '2026-10-17'
        );
        self::assertSame([], $preview);
        $paid = $this->runStatements([
            [
                'tender' => 'check',
                'status' => 'paid',
                'posting_date' => '2026-10-01',
                'rent_month' => 'not-a-day',
            ],
        ]);
        $this->payoutOnce($paid, $paid)->numberChecks('00000000-0000-7000-8000-000000000020', 80);
    }

    public function testARunCannotSkipToPaid(): void
    {
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('a payout run status cannot skip');
        (new PayoutRunLines($this->runStatements([])))->mark(
            '00000000-0000-7000-8000-000000000020',
            'reviewed'
        );
    }

    public function testHeldVendorsAreListedOffThePack(): void
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class ($pdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [[
                    'code' => 'H1',
                    'first_name' => 'Hold',
                    'last_name' => 'Vendor',
                    'payout_mode' => 'hold',
                ]];
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, 'check_void') || str_contains($sql, 'AS check_void')) {
                    return [['check_void' => 'voided']];
                }

                return [];
            }
        };
        $store = new PayoutRunStore($statements, new OccupiedPack($statements));
        self::assertSame('hold', $store->excluded()[0]['mode'] ?? null);
        self::assertTrue($store->checksVoided('00000000-0000-7000-8000-000000000020'));
    }

    public function testARaceForTheDraftKeepsTheWinner(): void
    {
        $pdo = $this->createStub(PDO::class);
        $winner = '00000000-0000-7000-8000-000000000033';
        $duplicate = $this->duplicateKey(...);
        $statements = new class ($pdo, $winner, $duplicate) extends Statements {
            private int $opens = 0;

            public function __construct(PDO $pdo, private readonly string $winner, private readonly \Closure $duplicate)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, "status = 'draft'")) {
                    $this->opens++;
                    if ($this->opens === 1) {
                        return [];
                    }

                    return [['id' => $this->winner]];
                }
                if (str_contains($sql, 'INSERT INTO payout_runs')) {
                    throw ($this->duplicate)();
                }

                return [];
            }

            public function change(string $sql, array $params = []): void
            {
            }
        };
        $id = (new PayoutRunStore($statements, new OccupiedPack($statements)))->ensureDraft(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            '2026-10-17',
            'check'
        );
        self::assertSame($winner, $id);
    }

    public function testALostDraftRaceIsRethrown(): void
    {
        $pdo = $this->createStub(PDO::class);
        $duplicate = $this->duplicateKey(...);
        $statements = new class ($pdo, $duplicate) extends Statements {
            public function __construct(PDO $pdo, private readonly \Closure $duplicate)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, 'INSERT INTO payout_runs')) {
                    throw ($this->duplicate)();
                }

                return [];
            }
        };
        $this->expectException(PDOException::class);
        (new PayoutRunStore($statements, new OccupiedPack($statements)))->ensureDraft(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            '2026-10-17',
            'check'
        );
    }

    public function testAPackRowNeedsStringFields(): void
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class ($pdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                return [[
                    'vendor_id' => 1,
                    'code' => 'A',
                    'name' => 'A',
                    'booth' => '',
                    'booth_id' => '',
                    'mode' => 'pay',
                ]];
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        (new PayoutRunStore($statements, new OccupiedPack($statements)))->pack(
            '00000000-0000-7000-8000-000000000020'
        );
    }

    public function testACloseFailureIsADatabaseRefusal(): void
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class ($pdo) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function transaction(callable $work): void
            {
                throw new PDOException('closed');
            }
        };
        $this->expectException(DatabaseRefusal::class);
        $this->payoutOnce($statements, $statements)->pay(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            '2026-10-17',
            'check',
            null
        );
    }

    private function duplicateKey(): PDOException
    {
        return new class extends PDOException {
            public function __construct()
            {
                parent::__construct('duplicate');
                $this->code = '23505';
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $run
     * @param list<array<string, mixed>> $checks
     * @param list<array<string, mixed>> $history
     */
    private function runStatements(array $run, array $checks = [], array $history = []): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo, $run, $checks, $history) extends Statements {
            /**
             * @param list<array<string, mixed>> $run
             * @param list<array<string, mixed>> $checks
             * @param list<array<string, mixed>> $history
             */
            public function __construct(
                PDO $pdo,
                private readonly array $run,
                private readonly array $checks,
                private readonly array $history,
            ) {
                parent::__construct($pdo);
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, 'payout_run_lines')) {
                    return $this->checks;
                }

                return $this->run;
            }

            public function rows(string $sql): array
            {
                return $this->history;
            }

            public function transaction(callable $work): void
            {
                $work();
            }

            public function change(string $sql, array $params = []): void
            {
            }

            public function affected(string $sql, array $params): int
            {
                return 0;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(string $vendorId, int $check, string $number): array
    {
        return [
            'vendor_id' => $vendorId,
            'code' => 'ADA',
            'name' => 'Ada Lane',
            'booth' => 'A-1',
            'sold_on' => '2026-10-02',
            'item_code' => 'hat',
            'description' => 'Hat',
            'price_minor' => 5000,
            'quantity' => 1,
            'commission_minor' => 2500,
            'settled_minor' => 0,
            'check_minor' => $check,
            'owes_minor' => 0,
            'check_number' => $number,
        ];
    }

    private function payouts(Statements $statements, string $payable): PayoutStore
    {
        $pdo = $this->createStub(PDO::class);
        $query = $this->createStub(\PDOStatement::class);
        $query->method('execute')->willReturn(true);
        $query->method('fetchColumn')->willReturn($payable);
        $pdo->method('prepare')->willReturn($query);

        return new PayoutStore($pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow());
    }

    private function payoutOnce(Statements $runs, Statements $sheets): PayoutOnce
    {
        $store = new PayoutRunStore($runs, new OccupiedPack($runs));

        return new PayoutOnce(
            $store,
            new PayoutRunSheets($sheets),
            new PayoutSettler($this->createStub(LedgerStore::class), $this->payouts($runs, '0'), $store, new PayoutRunLines($runs)),
            new PayoutDisburser(
                $this->createStub(LedgerStore::class),
                $this->payouts($runs, '0'),
                $store,
                new PayoutRunLines($runs),
                new PayoutRunChecks($runs),
            ),
            $runs,
            new CheckWords(),
            new RentShortfall($runs),
            $this->createStub(\PerfectConsign\Bootstrap\SaleCeiling::class),
        );
    }
}
