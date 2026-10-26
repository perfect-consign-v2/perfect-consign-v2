<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\LateFeeDue;
use PerfectConsign\Feature\Rent\RentForm;
use PerfectConsign\Feature\Rent\RentOwed;
use PerfectConsign\Feature\Rent\RentPayment;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class RentCoverageTest extends TestCase
{
    public function testTheRentFormKeepsCheckedIdsAndAQuery(): void
    {
        $form = new RentForm();
        self::assertSame(['a', 'b'], $form->checked([' a ', '', 'b', 3]));
        self::assertSame(['only'], $form->checked('only'));
        self::assertSame([], $form->checked(''));
        self::assertSame([], $form->checked(12));
        self::assertSame('from-query', $form->chosen('from-query', 'posted'));
        self::assertSame('posted', $form->chosen('', 'posted'));
        self::assertTrue($form->needsDrawer([], '', '', '', '1.00'));
        self::assertFalse($form->needsDrawer(['fee'], '', '', '', ''));
    }

    public function testALateFeeNeedsAPeriodAndAnAmount(): void
    {
        $fee = new LateFee(
            $this->createStub(LedgerStore::class),
            $this->createStub(Statements::class),
        );
        try {
            $fee->post('00000000-0000-7000-8000-000000000004', 'October', '2026-10-01', 150);
            self::fail('a late fee without a period was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the late fee.', $error->getMessage());
        }
        try {
            $fee->post('00000000-0000-7000-8000-000000000004', '2026-10', '2026-10-01', 0);
            self::fail('a zero late fee was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the late fee.', $error->getMessage());
        }
        try {
            $fee->waive('00000000-0000-7000-8000-000000000099', 'October');
            self::fail('a waive without a date was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the date of the late fee.', $error->getMessage());
        }
    }

    public function testLateFeesAreSkippedWhenThePolicyIsOff(): void
    {
        $idle = new LateFee(
            $this->createStub(LedgerStore::class),
            $this->createStub(Statements::class),
        );
        $off = new LateFeeDue(
            $this->mallStatements($this->mallRow(false, false, 0, 500, 250)),
            new MallPayoutSettings($this->mallStatements($this->mallRow(false, false, 0, 500, 250))),
            $idle,
        );
        $off->apply('not-a-day');
        $off->apply('2026-10-15');
        $zero = new LateFeeDue(
            $this->mallStatements($this->mallRow(true, true, 0, 0, 250)),
            new MallPayoutSettings($this->mallStatements($this->mallRow(true, true, 0, 0, 250))),
            $idle,
        );
        $zero->apply('2026-10-15');
        $existing = $this->createStub(Statements::class);
        $existing->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000fe']]);
        $posted = new LateFee($this->createStub(LedgerStore::class), $existing);
        $books = $this->moveStatements();
        $live = new LateFeeDue(
            $books,
            new MallPayoutSettings($this->mallStatements($this->mallRow(true, true, 5, 500, 250))),
            $posted,
        );
        $live->apply('2026-10-15');
        try {
            $live->waive('00000000-0000-7000-8000-000000000099', '2026-10-15');
            self::fail('a missing late fee was waived');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a late fee.', $error->getMessage());
        }
    }

    public function testOpenRentNeedsAVendorWhoOwes(): void
    {
        $settings = new MallPayoutSettings($this->mallStatements($this->mallRow(true, false, 0, 0, 0)));
        $pdo = $this->createStub(PDO::class);
        $statements = $this->createStub(Statements::class);
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $owed = new RentOwed($statements, new PayoutStore($pdo, $statements, $runs, new PayoutWindow()), $settings, new RentPayment());
        self::assertNull($owed->figure('', '', '2026-10-15'));
        $named = $this->createStub(Statements::class);
        $named->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'open_minor')) {
                return [['open_minor' => 0]];
            }
            if (str_contains($sql, 'AS vendor_id')) {
                return [['vendor_id' => '00000000-0000-7000-8000-0000000000a1']];
            }

            return [];
        });
        $empty = new RentOwed($named, new PayoutStore($pdo, $named, $runs, new PayoutWindow()), $settings, new RentPayment());
        self::assertNull($empty->figure('', '00000000-0000-7000-8000-0000000000b1', '2026-10-15'));
        $full = $this->createStub(Statements::class);
        $full->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'open_minor')) {
                return [['open_minor' => 2500]];
            }
            if (str_contains($sql, 'late_minor')) {
                return [['late_minor' => 0]];
            }
            if (str_contains($sql, 'vendor_name')) {
                return [['vendor_name' => 'Ada Lane']];
            }
            if (str_contains($sql, 'JOIN booths')) {
                return [[
                    'id' => '00000000-0000-7000-8000-0000000000b1',
                    'code' => 'A1',
                ]];
            }
            if (str_contains($sql, 'AS vendor_id')) {
                return [['vendor_id' => '00000000-0000-7000-8000-0000000000a1']];
            }

            return [];
        });
        $figure = (new RentOwed($full, new PayoutStore($pdo, $full, $runs, new PayoutWindow()), $settings, new RentPayment()))->figure(
            '00000000-0000-7000-8000-0000000000a1',
            '00000000-0000-7000-8000-0000000000b1',
            '2026-10-15'
        );
        self::assertNotNull($figure);
        self::assertSame('Ada Lane', $figure['vendor_name']);
        self::assertSame(2500, $figure['owed']);
        self::assertSame('A1', $figure['booth']);
    }

    public function testPrepaidRentUsesThePackAndAssignedBooths(): void
    {
        $vendor = '00000000-0000-7000-8000-0000000000a1';
        $other = '00000000-0000-7000-8000-0000000000a2';
        $booth = '00000000-0000-7000-8000-0000000000b1';
        $pdo = $this->createStub(PDO::class);
        $empty = $this->prepaidOwed($pdo, $vendor, $other, $booth, 0, false, []);
        self::assertNull($empty->figure('', '', '2026-10-15'));
        self::assertNull($empty->figure($vendor, $booth, '2026-10-15'));
        $noDebtor = $this->prepaidOwed($pdo, $vendor, $other, $booth, 2500, false, []);
        self::assertNull($noDebtor->figure($vendor, $booth, '2026-10-15'));
        $assigned = $this->prepaidOwed($pdo, $vendor, $other, $booth, 2500, true, []);
        $undated = $assigned->figure($vendor, $booth, 'October');
        self::assertNotNull($undated);
        self::assertSame('A1', $undated['booth']);
        $figured = $assigned->figure($vendor, $booth, '2026-10-15');
        self::assertNotNull($figured);
        self::assertSame('Ada Lane', $figured['vendor_name']);
        self::assertSame($booth, $figured['booth_id']);
        self::assertSame('A1', $figured['booth']);
        self::assertSame(2500, $figured['owed']);
        self::assertSame('25.00', $figured['collect']);
        $open = $this->prepaidOwed($pdo, $vendor, $other, $booth, 2500, true, [
            ['id' => $booth, 'code' => 'A1'],
        ]);
        $named = $open->figure($vendor, $booth, '2026-10-15');
        self::assertNotNull($named);
        self::assertSame('A1', $named['booth']);
    }

    /**
     * @param list<array{id: string, code: string}> $openBooths
     */
    private function prepaidOwed(
        PDO $pdo,
        string $vendor,
        string $other,
        string $booth,
        int $owes,
        bool $listed,
        array $openBooths,
    ): RentOwed {
        $row = $this->mallRow(true, false, 0, 0, 0);
        $row['rent_prepaid_from_prior_sales'] = true;
        $statements = new class ($pdo, $vendor, $other, $booth, $owes, $listed, $openBooths, $row) extends Statements {
            /**
             * @param list<array{id: string, code: string}> $openBooths
             * @param array<string, mixed> $mall
             */
            public function __construct(
                PDO $pdo,
                private readonly string $vendor,
                private readonly string $other,
                private readonly string $booth,
                private readonly int $owes,
                private readonly bool $listed,
                private readonly array $openBooths,
                private readonly array $mall,
            ) {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [$this->mall];
            }

            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, "status = 'draft'")) {
                    return [];
                }
                if (
                    str_contains($sql, 'stall_payout_quote')
                    || str_contains($sql, 'stall_payout_run_quote')
                    || (str_contains($sql, 'stall_payout_pack') && str_contains($sql, 'vendor_id ='))
                ) {
                    if ($this->owes < 1) {
                        return [];
                    }

                    return [[
                        'payable' => 0,
                        'rent' => $this->owes,
                        'check_amount' => 0,
                        'owe_amount' => $this->owes,
                        'settled_minor' => 0,
                        'rent_from_sales' => true,
                    ]];
                }
                if (str_contains($sql, 'owes > 0')) {
                    if (!$this->listed) {
                        return [[
                            'vendor_id' => $this->other,
                            'code' => 'Z1',
                            'name' => 'Zoe',
                            'booth_id' => '00000000-0000-7000-8000-0000000000b2',
                            'booth' => 'Z1',
                            'owes' => 100,
                        ]];
                    }

                    return [
                        [
                            'vendor_id' => $this->other,
                            'code' => 'Z1',
                            'name' => 'Zoe',
                            'booth_id' => '00000000-0000-7000-8000-0000000000b2',
                            'booth' => 'Z1',
                            'owes' => 100,
                        ],
                        [
                            'vendor_id' => $this->vendor,
                            'code' => 'A1',
                            'name' => 'Ada Lane',
                            'booth_id' => $this->booth,
                            'booth' => 'A1',
                            'owes' => $this->owes,
                        ],
                    ];
                }
                if (str_contains($sql, 'late_minor')) {
                    return [['late_minor' => 0]];
                }
                if (str_contains($sql, 'HAVING sum')) {
                    return $this->openBooths;
                }
                if (str_contains($sql, 'SELECT booth.code')) {
                    return [['code' => 'A1']];
                }

                return [];
            }
        };
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));

        return new RentOwed(
            $statements,
            new PayoutStore($pdo, $statements, $runs, new PayoutWindow()),
            new MallPayoutSettings($statements),
            new RentPayment()
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mallStatements(array $row): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo, $row) extends Statements {
            /**
             * @param array<string, mixed> $row
             */
            public function __construct(PDO $pdo, private readonly array $row)
            {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [$this->row];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mallRow(bool $rentals, bool $late, int $grace, int $first, int $next): array
    {
        return [
            'operating_mode' => 'both',
            'payout_cadence' => 'monthly',
            'rent_prepaid_from_prior_sales' => false,
            'booth_rentals_enabled' => $rentals,
            'rent_cycle_day' => 1,
            'late_fees_enabled' => $late,
            'late_fee_grace_days' => $grace,
            'late_fee_first_minor' => $first,
            'late_fee_next_minor' => $next,
        ];
    }

    private function moveStatements(): Statements
    {
        $pdo = $this->createStub(PDO::class);

        return new class ($pdo) extends Statements {
            public function rows(string $sql): array
            {
                if (str_contains($sql, 'late-fee:%')) {
                    return [['posting_key' => null], ['posting_key' => 'late-fee:booth:2026-08']];
                }

                return [
                    [
                        'posting_key' => 'other',
                        'source_type' => 'rent_charge',
                        'booth_id' => null,
                        'debit_minor' => 1000,
                        'credit_minor' => 0,
                    ],
                    [
                        'posting_key' => 'rent-charge:booth',
                        'source_type' => 'rent_charge',
                        'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                        'debit_minor' => 1000,
                        'credit_minor' => 0,
                    ],
                    [
                        'posting_key' => 'rent-charge:booth:2026-13',
                        'source_type' => 'late_fee',
                        'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                        'debit_minor' => 1000,
                        'credit_minor' => 50,
                    ],
                    [
                        'posting_key' => 'rent-charge:booth:2026-08',
                        'source_type' => 'rent_charge',
                        'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                        'debit_minor' => 0,
                        'credit_minor' => 0,
                    ],
                    [
                        'posting_key' => 'rent-charge:booth:2026-09',
                        'source_type' => 'rent_charge',
                        'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                        'debit_minor' => 4000,
                        'credit_minor' => 0,
                    ],
                    [
                        'posting_key' => 'rent-charge:booth:2026-09-15',
                        'source_type' => 'rent_charge',
                        'booth_id' => '00000000-0000-7000-8000-0000000000b1',
                        'debit_minor' => 1500,
                        'credit_minor' => 0,
                    ],
                ];
            }
        };
    }
}
