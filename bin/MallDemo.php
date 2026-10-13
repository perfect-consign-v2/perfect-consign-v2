<?php

declare(strict_types=1);

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Pos\TicketBuyer;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Feature\Pos\TicketQueue;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Feature\Rent\RentLines;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MallDemo
{
    private const REGISTER = '01900000-0000-7000-8000-000000000d10';

    /** @var list<int> */
    private const BASKET = [18000, 12000, 9500, 7000];

    /** @var list<int> */
    private const SHELF = [2400, 4500, 8900, 15000, 3200, 6700, 11000, 5400];

    /** @var list<string> */
    private const GOODS = [
        'Oak side chair',
        'Brass table lamp',
        'Quilted coverlet',
        'Stoneware pitcher',
        'Walnut mirror',
        'Linen tablecloth',
        'Cast iron skillet',
        'Pine blanket chest',
        'Silver plate tray',
        'Wool throw',
        'Ceramic lamp',
        'Mahogany stool',
        'Glass cake stand',
        'Copper kettle',
        'Framed botanical',
        'Wicker armchair',
        'Enamel colander',
        'Cedar keepsake box',
        'Brass candlesticks',
        'Cotton quilt',
        'Oak writing desk',
        'Porcelain vase',
        'Leather satchel',
        'Iron bookends',
        'Maple cutting board',
        'Crystal decanter',
        'Painted cabinet',
        'Wool rug',
        'Tin bread box',
        'Upholstered bench',
    ];

    private readonly Statements $statements;

    private readonly LedgerStore $ledger;

    private readonly PartyStore $parties;

    private readonly TicketStore $tickets;

    private readonly SalePoster $poster;

    private readonly RentStore $rent;

    private readonly PayoutRunStore $runs;

    private readonly PayoutSettler $settler;

    private readonly PayoutDisburser $disburser;

    /** @var array<string, string> */
    private array $vendors = [];

    /** @var array<string, string> */
    private array $booths = [];

    /** @var array<string, list<array{id: string, barcode: string}>> */
    private array $queues = [];

    /** @var list<array{journal: string, item: string, due: int, date: string, vendor: string}> */
    private array $sales = [];

    /** @var list<string> */
    private array $brands = [];

    private int $itemNo = 0;

    private bool $roundCash = false;

    public function __construct(private readonly PDO $pdo, private readonly string $schema)
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new InvalidArgumentException('refusing an unexpected schema');
        }
        $this->statements = new Statements($pdo);
        $this->ledger = new LedgerStore($pdo, $this->statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $this->parties = new PartyStore($this->statements);
        $pricing = new TicketPricing($this->statements);
        $this->tickets = new TicketStore($this->statements, $pricing, new TicketBuyer($this->statements), new TicketQueue($this->statements, $pricing), new TicketCustomer($this->statements, new CustomerStore($this->statements)));
        $this->poster = new SalePoster($this->ledger, $this->statements, new SaleReceipt($this->ledger, $this->statements));
        $this->rent = new RentStore($this->statements, new MallSettings($this->statements), new RentLines(), $this->ledger);
        $this->runs = new PayoutRunStore($this->statements, new OccupiedPack($this->statements));
        $payouts = new PayoutStore($pdo, $this->statements, $this->runs, new PayoutWindow());
        $lines = new PayoutRunLines($this->statements);
        $this->settler = new PayoutSettler($this->ledger, $payouts, $this->runs, $lines);
        $this->disburser = new PayoutDisburser($this->ledger, $payouts, $this->runs, $lines);
    }

    public function load(): void
    {
        $this->useMall();
        $this->statements->change(
            'UPDATE mall SET commission_bps = ?, tax_bps = ?, floor_days = ?, rent_prepaid_from_prior_sales = true',
            [2000, 8750, 90]
        );
        $this->statements->change('UPDATE registers SET name = ? WHERE id = ?::uuid', ['Front desk', self::REGISTER]);
        $this->statements->change(
            "INSERT INTO map_floors (name, sort_order) VALUES ('Annex', 1)",
            []
        );
        $this->brands();
        $this->floor();
        $this->aprilPeople();
        $this->stock('ADA', '2026-04-01', 26);
        $this->stock('BEA', '2026-04-01', 26);
        $this->stock('DAN', '2026-04-01', 16);
        $this->stock('CAL', '2026-05-15', 22);
        $this->stock('EVE', '2026-08-01', 10);
        $this->stock('LEO', '2026-04-01', 8);
        $this->stock('GIN', '2026-09-20', 0);
        $this->stock('IRI', '2026-11-01', 4);
        $this->stock('JON', '2026-11-01', 4);
        $this->stock('MAX', '2026-08-01', 0);
        $this->stock('NIA', '2026-08-01', 0);
        $this->itemNotes();
        $this->opening();
        foreach (['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'] as $month) {
            $this->month($month);
        }
        $this->october();
        $this->waitList();
        $this->sideDoor();
        fwrite(STDOUT, "demo posted through 2026-10-05\n");
    }

    private function useMall(): void
    {
        $this->pdo->exec('SET search_path TO ' . $this->schema . ', pg_catalog, extensions');
    }

    private function brands(): void
    {
        foreach (['Pyrex', 'Lane', 'Woolrich', 'Fiesta'] as $name) {
            $rows = $this->statements->bound('INSERT INTO brands (name) VALUES (?) RETURNING id', [$name]);
            $id = $rows[0]['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new RuntimeException('a brand was not saved');
            }
            $this->brands[] = $id;
        }
    }

    private function floor(): void
    {
        $floor = $this->statements->rows("SELECT id FROM map_floors WHERE name = 'Main Floor'");
        $floorId = $floor[0]['id'] ?? null;
        if (!is_string($floorId) || $floorId === '') {
            throw new RuntimeException('Main Floor is missing');
        }
        $places = [
            'A-1' => [2, 2, 30000],
            'A-2' => [14, 2, 25000],
            'B-1' => [26, 2, 15000],
            'B-2' => [38, 2, 20000],
            'C-1' => [2, 16, 40000],
            'C-2' => [14, 16, 7500],
            'D-1' => [26, 16, 15000],
            'D-2' => [38, 16, 10000],
        ];
        foreach ($places as $code => $place) {
            $id = $this->ledger->newId();
            $this->statements->change(
                'INSERT INTO booths (id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id)
                 VALUES (?::uuid, ?, ?, 10, 10, ?, ?, ?::uuid)',
                [$id, $code, 'Booth ' . $code, $place[0], $place[1], $floorId]
            );
            $this->parties->setAskingRent($id, $place[2]);
            $this->booths[$code] = $id;
        }
    }

    private function aprilPeople(): void
    {
        $this->vendor('ADA', 'Ada', 'Lane', '555-0140', 'ada@example.com', '14 Cedar St', 2000, '2026-04-01', '', 'Moved in April. Store keeps 20 percent.');
        $this->vendor('BEA', 'Bea', 'Ortiz', '555-0142', 'bea@example.com', '8 Harbor Ave', 1500, '2026-04-01', '', 'Store keeps 15 percent.');
        $this->vendor('CAL', 'Cal', 'Nguyen', '555-0144', 'cal@example.com', '31 Pine Rd', 2500, '2026-05-15', '', 'Moved in mid-May. Store keeps 25 percent.');
        $this->vendor('DAN', 'Dana', 'Shah', '555-0146', 'dana@example.com', '2 Elm Ct', 2000, '2026-04-01', '2026-07-31', 'Left at the end of July. Inactive once that payout cleared.');
        $this->vendor('EVE', 'Eve', 'Park', '555-0148', 'eve@example.com', '19 Oak Ln', 2000, '2026-08-01', '', 'Took booth B-2 in August at the new asking rent.');
        $this->vendor('LEO', 'Leo', 'Marsh', '555-0150', 'leo@example.com', '7 Birch Way', 2000, '2026-04-01', '2026-05-31', 'Left in May. Inactive once the June payout cleared.');
        $this->vendor('GIN', 'Gina', 'Holt', '555-0152', 'gina@example.com', '44 Mill St', 2000, '2026-09-20', '', 'Moved into C-1 in September. Sales do not cover the booth.');
        $this->vendor('IRI', 'Iris', 'Chen', '555-0154', 'iris@example.com', '3 Lake Dr', 2000, '2026-11-01', '', 'Arrives in November, after Bea leaves A-2. Resale certificate on file.');
        $this->vendor('JON', 'Jon', 'Hale', '555-0156', 'jon@example.com', '18 River Rd', 2000, '2026-11-01', '', 'Arrives in November for B-1. Payouts are on hold.');
        $this->vendor('MAX', 'Max', 'Reed', '555-0158', 'max@example.com', '9 Dock St', 2000, '2026-08-01', '', 'Took D-1 in August. August, September, and October rent was never paid.');
        $this->vendor('NIA', 'Nia', 'Cole', '555-0160', 'nia@example.com', '21 Wharf Ln', 2000, '2026-08-01', '', 'Took D-2 in August. August, September, and October rent was never paid.');
        $this->assign('A-1', 'ADA', '2026-04-01', '', 30000);
        $this->assign('A-2', 'BEA', '2026-04-01', '', 25000);
        $this->assign('B-2', 'DAN', '2026-04-01', '', 20000);
        $this->assign('C-2', 'LEO', '2026-04-01', '', 7500);
        $this->statements->change(
            'UPDATE vendors SET tax_exempt = true, tax_exempt_number = ? WHERE id = ?::uuid',
            ['RESALE-1042', $this->vendors['IRI']]
        );
        $this->statements->change(
            'UPDATE vendors SET payout_mode = ? WHERE id = ?::uuid',
            ['hold', $this->vendors['JON']]
        );
    }

    private function month(string $month): void
    {
        $this->useMall();
        fwrite(STDOUT, $month . "\n");
        $session = $this->openDrawer($month . '-01');
        if ($month === '2026-04') {
            $this->chargeAndCollect($session, 'A-1', '2026-04-01');
            $this->chargeAndCollect($session, 'A-2', '2026-04-01');
            $this->chargeAndCollect($session, 'B-2', '2026-04-01');
            $this->chargeAndCollect($session, 'C-2', '2026-04-01');
        }
        if ($month === '2026-05') {
            $this->assign('B-1', 'CAL', '2026-05-15', '', 15000);
            $this->chargeAndCollect($session, 'B-1', '2026-05-15');
        }
        if ($month === '2026-07') {
            $this->taxPayment();
        }
        if ($month === '2026-09') {
            $this->assign('C-1', 'GIN', '2026-09-20', '', 40000);
            $this->rent->postCharge($this->booths['C-1'], '2026-09-20');
            $this->collectRent($session, 'C-1', '2026-09-20', 5000, '8801');
        }
        $who = ['ADA', 'BEA'];
        if ($month <= '2026-07') {
            $who[] = 'DAN';
        }
        if ($month <= '2026-05') {
            $who[] = 'LEO';
        }
        if ($month >= '2026-05') {
            $who[] = 'CAL';
        }
        if ($month >= '2026-08') {
            $who[] = 'EVE';
        }
        foreach ($who as $code) {
            if ($code === 'CAL' && $month < '2026-05') {
                continue;
            }
            $days = $code === 'CAL' && $month === '2026-05' ? [16, 20, 24, 28] : [8, 16, 22, 27];
            foreach ($days as $day) {
                $this->sell($session, $code, $month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT), false);
            }
        }
        if ($month === '2026-06') {
            $this->giveBack($session, '2026-06-18', $this->findSale('BEA', '2026-06'));
        }
        if ($month === '2026-08') {
            $this->payOpenRent($session, 'B-2', '2026-08-03');
            $voided = $this->findSale('ADA', '2026-08');
            $this->poster->voidSale($session, $voided['date'], $voided['journal']);
            $this->adjustment();
        }
        $this->closeDrawer($session, $month, $month === '2026-06' ? 200 : 0);
        if ($month === '2026-05') {
            $this->parties->endAssignment($this->assignmentId('C-2', 'LEO'), '2026-05-31');
        }
        if ($month === '2026-07') {
            $this->parties->endAssignment($this->assignmentId('B-2', 'DAN'), '2026-07-31');
            $this->parties->setAskingRent($this->booths['A-1'], 35000);
            $this->parties->setAskingRent($this->booths['B-2'], 27500);
            $this->assign('B-2', 'EVE', '2026-08-01', '', 27500);
            $this->assign('D-1', 'MAX', '2026-08-01', '', 15000);
            $this->assign('D-2', 'NIA', '2026-08-01', '', 10000);
        }
        $next = (new DateTimeImmutable($month . '-01'))->modify('first day of next month')->format('Y-m-d');
        if ($month !== '2026-09') {
            $this->payout($next);
        } else {
            fwrite(STDOUT, "  October payout left unpaid\n");
        }
        if ($month === '2026-05') {
            $this->parties->setActive($this->vendors['LEO'], false);
        }
        if ($month === '2026-07') {
            $this->parties->setActive($this->vendors['DAN'], false);
        }
        if ($month === '2026-09') {
            $this->parties->endAssignment($this->assignmentId('A-2', 'BEA'), '2026-10-31');
            $this->parties->endAssignment($this->assignmentId('B-1', 'CAL'), '2026-10-18');
            $this->assign('A-2', 'IRI', '2026-11-01', '', 25000);
            $this->assign('B-1', 'JON', '2026-11-01', '', 15000);
        }
    }

    private function october(): void
    {
        $this->useMall();
        fwrite(STDOUT, "2026-10\n");
        $this->roundCash = true;
        $this->statements->change('UPDATE mall SET cash_round_nickel = true', []);
        $session = $this->openDrawer('2026-10-01');
        $this->rent->postCharge($this->booths['D-1'], '2026-10-01');
        $this->rent->postCharge($this->booths['D-2'], '2026-10-01');
        foreach (['ADA', 'BEA', 'CAL', 'EVE'] as $code) {
            $this->sell($session, $code, '2026-10-02', false);
            $this->sell($session, $code, '2026-10-04', $code === 'ADA');
        }
        $this->counter($session);
    }

    private function vendor(
        string $code,
        string $first,
        string $last,
        string $phone,
        string $email,
        string $address,
        int $commission,
        string $starts,
        string $ends,
        string $notes,
    ): void {
        $id = $this->parties->addVendor(new VendorDraft([
            'code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'agreement_starts_on' => $starts,
            'agreement_ends_on' => $ends,
            'tax_id' => '',
            'notes' => $notes,
            'tax_exempt' => '',
            'tax_exempt_number' => '',
        ], $commission));
        $this->vendors[$code] = $id;
        $this->queues[$code] = [];
    }

    private function assign(string $booth, string $vendor, string $starts, string $ends, int $rent): void
    {
        $this->parties->addAssignment($this->booths[$booth], $this->vendors[$vendor], $starts, $ends, $rent);
    }

    private function assignmentId(string $booth, string $vendor): string
    {
        $rows = $this->statements->bound(
            'SELECT id FROM booth_assignments WHERE booth_id = ?::uuid AND vendor_id = ?::uuid AND ends_on IS NULL',
            [$this->booths[$booth], $this->vendors[$vendor]]
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('that assignment is missing');
        }

        return $id;
    }

    private function stock(string $vendor, string $intake, int $sellable): void
    {
        $brand = 0;
        for ($index = 0; $index < $sellable + count(self::SHELF); $index++) {
            $this->itemNo++;
            $id = $this->ledger->newId();
            $price = $index < $sellable ? self::BASKET[$index % count(self::BASKET)] : self::SHELF[$index - $sellable];
            $barcode = 'PC' . str_pad((string) $this->itemNo, 12, '0', STR_PAD_LEFT);
            $code = $vendor . '-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $this->statements->change(
                'INSERT INTO items (id, code, vendor_id, brand_id, description, price_minor, barcode, intake_on, quantity)
                 VALUES (?::uuid, ?, ?::uuid, ?::uuid, ?, ?, ?, ?::date, 1)',
                [
                    $id,
                    $code,
                    $this->vendors[$vendor],
                    $this->brands[$brand % count($this->brands)],
                    self::GOODS[$this->itemNo % count(self::GOODS)],
                    $price,
                    $barcode,
                    $intake,
                ]
            );
            $brand++;
            if ($index < $sellable) {
                $this->queues[$vendor][] = ['id' => $id, 'barcode' => $barcode];
            }
        }
    }

    private function openDrawer(string $day): string
    {
        $id = $this->ledger->newId();
        $this->statements->change(
            'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor, opened_at)
             VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0, ?::timestamptz)',
            [$id, self::REGISTER, 'Nora Hale', DenominationCount::json(DenominationCount::forAmount(0)), $day . ' 09:00:00-07']
        );

        return $id;
    }

    private function closeDrawer(string $sessionId, string $month, int $short = 0): void
    {
        $end = (new DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');
        if ($short > 0) {
            $key = $this->ledger->newId();
            $this->ledger->post(new JournalDraft(
                postingKey: 'register-variance:' . $key,
                postingDate: $end,
                source: new JournalSource('register_variance', $key, 'Register over/short'),
                lines: [
                    PostedLines::entry($this->ledger->roleAccount('cash_over_short'), $short, 0, null, null),
                    PostedLines::entry($this->ledger->roleAccount('cash'), 0, $short, null, null),
                ],
                sessionId: $sessionId,
            ));
        }
        $rows = $this->statements->bound('SELECT stall_register_expected_cash(?::uuid) AS cash', [$sessionId]);
        $expected = RowValue::integer($rows[0]['cash'] ?? null);
        $counted = $expected - $short;
        $updated = $this->statements->affected(
            'UPDATE register_sessions
             SET closing_counts = ?::jsonb, counted_cash_minor = ?, counted_card_minor = 0, counted_checks_minor = 0, closed_at = ?::timestamptz
             WHERE id = ?::uuid AND closed_at IS NULL',
            [DenominationCount::json(DenominationCount::forAmount($counted)), $counted, $end . ' 18:00:00-07', $sessionId]
        );
        if ($updated !== 1) {
            throw new RuntimeException('the register did not close');
        }
    }

    private function sell(string $sessionId, string $vendor, string $date, bool $card): void
    {
        $next = array_shift($this->queues[$vendor]);
        if ($next === null) {
            throw new RuntimeException($vendor . ' is out of demo items on ' . $date);
        }
        $this->tickets->scan($sessionId, $next['barcode']);
        $cart = $this->tickets->cart($sessionId);
        $due = $cart['due'];
        $this->payCart($sessionId, $date, $card, null);
        $journal = $this->statements->bound(
            'SELECT posted_journal_id FROM tickets WHERE id = ?::uuid',
            [$cart['ticket_id']]
        );
        $journalId = $journal[0]['posted_journal_id'] ?? null;
        if (!is_string($journalId) || $journalId === '') {
            throw new RuntimeException('a sale did not post');
        }
        $this->sales[] = [
            'journal' => $journalId,
            'item' => $next['id'],
            'due' => $due,
            'date' => $date,
            'vendor' => $vendor,
        ];
    }

    /**
     * @return array{journal: string, item: string, due: int, date: string, vendor: string}
     */
    private function findSale(string $vendor, string $month): array
    {
        $chosen = null;
        foreach ($this->sales as $sale) {
            if ($sale['vendor'] !== $vendor || !str_starts_with($sale['date'], $month)) {
                continue;
            }
            if ($chosen === null || $sale['due'] < $chosen['due']) {
                $chosen = $sale;
            }
        }
        if ($chosen === null) {
            throw new RuntimeException('no ' . $vendor . ' sale in ' . $month);
        }

        return $chosen;
    }

    /**
     * @param array{journal: string, item: string, due: int, date: string, vendor: string} $sale
     */
    private function giveBack(string $sessionId, string $date, array $sale): void
    {
        $this->poster->returnItem(
            $sessionId,
            $date,
            $sale['journal'],
            $sale['item'],
            PostedLines::entry($this->ledger->roleAccount('cash'), 0, $sale['due'], null, null),
            null
        );
    }

    private function chargeAndCollect(string $sessionId, string $booth, string $date): void
    {
        $this->rent->postCharge($this->booths[$booth], $date);
        $this->payOpenRent($sessionId, $booth, $date);
    }

    private function payOpenRent(string $sessionId, string $booth, string $date): void
    {
        $rows = $this->statements->bound(
            "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS open_minor
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
               AND line.subledger_ref = ?",
            [$this->booths[$booth]]
        );
        $open = RowValue::integer($rows[0]['open_minor'] ?? null);
        if ($open < 1) {
            return;
        }
        $id = $this->ledger->newId();
        $this->ledger->post(new JournalDraft(
            postingKey: 'rent-payment:' . $id,
            postingDate: $date,
            source: new JournalSource('rent_payment', $id, 'Rent receipt'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('cash'), $open, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), 0, $open, 'booth', $this->booths[$booth]),
            ],
            sessionId: $sessionId,
            tenderedMinor: $open,
        ));
    }

    private function payout(string $postingDate): void
    {
        $this->useMall();
        $window = (new PayoutWindow())->window($postingDate);
        $runId = $this->runs->ensureDraft($window['from'], $window['to'], $window['rent'], $postingDate);
        $this->runs->review($runId);
        $this->settler->close($runId, $postingDate);
        $this->disburser->pay($runId, $postingDate);
        fwrite(STDOUT, '  payout ' . $window['from'] . ' through ' . $window['to'] . ' posted ' . $postingDate . "\n");
    }

    private function opening(): void
    {
        $this->ledger->post(new JournalDraft(
            postingKey: 'opening',
            postingDate: '2026-03-31',
            source: new JournalSource('opening', 'opening', 'Opening balance'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('bank'), 2000000, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('retained_earnings'), 0, 2000000, null, null),
            ],
        ));
    }

    private function taxPayment(): void
    {
        $this->ledger->post(new JournalDraft(
            postingKey: 'tax-payment:2026-q2',
            postingDate: '2026-07-02',
            source: new JournalSource('tax_payment', '2026-q2', 'Sales tax payment'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('sales_tax_payable'), 15000, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('bank'), 0, 15000, null, null),
            ],
        ));
    }

    private function adjustment(): void
    {
        $this->ledger->post(new JournalDraft(
            postingKey: 'adjustment:supplies-aug',
            postingDate: '2026-08-12',
            source: new JournalSource('adjustment', 'supplies-aug', 'Shop supplies'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('operating_expense'), 4500, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('bank'), 0, 4500, null, null),
            ],
        ));
    }

    private function collectRent(string $sessionId, string $booth, string $date, int $amount, string $check): void
    {
        $id = $this->ledger->newId();
        $this->ledger->post(new JournalDraft(
            postingKey: 'rent-payment:' . $id,
            postingDate: $date,
            source: new JournalSource('rent_payment', $id, 'Rent receipt'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('checks'), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), 0, $amount, 'booth', $this->booths[$booth]),
            ],
            sessionId: $sessionId,
            checkNumber: $check,
        ));
    }

    private function itemNotes(): void
    {
        $this->statements->change(
            'UPDATE items SET quantity = 3 WHERE vendor_id = ?::uuid AND code = ?',
            [$this->vendors['ADA'], 'ADA-027']
        );
        $this->statements->change(
            'UPDATE items SET expires_on = ?::date WHERE vendor_id = ?::uuid AND code = ?',
            ['2026-12-15', $this->vendors['BEA'], 'BEA-034']
        );
        $this->statements->change(
            'UPDATE items SET commission_bps = ? WHERE vendor_id = ?::uuid AND code = ?',
            [1000, $this->vendors['CAL'], 'CAL-030']
        );
    }

    private function counter(string $sessionId): void
    {
        $this->tickets->scan($sessionId, $this->shelfBarcode('BEA'));
        $this->tickets->discount($sessionId, 'amount', 500, 'damaged');
        $this->payCart($sessionId, '2026-10-03', false, null);
        $this->tickets->scan($sessionId, $this->shelfBarcode('CAL'));
        $this->tickets->waive($sessionId, 'resale');
        $this->payCart($sessionId, '2026-10-03', false, '4402');
        $this->tickets->scan($sessionId, $this->shelfBarcode('EVE'));
        $cart = $this->tickets->cart($sessionId);
        (new LayawayBook($this->statements, $this->ledger))->hold($sessionId, '2026-10-03', $cart['ticket_id'], $cart['due'], [
            'lines' => [PostedLines::entry($this->ledger->roleAccount('cash'), 2000, 0, null, null)],
            'sum' => 2000,
            'check' => null,
            'cash' => 2000,
        ]);
        $this->tickets->scan($sessionId, $this->shelfBarcode('ADA'));
        $this->tickets->park($sessionId);
        (new GiftIssue($this->statements, $this->ledger))->issue($sessionId, '2026-10-03', 'GC-1001', 2500, 'cash', null);
        $drop = $this->ledger->newId();
        $this->ledger->post(new JournalDraft(
            postingKey: 'cash-drop:' . $drop,
            postingDate: '2026-10-03',
            source: new JournalSource('cash_drop', $drop, 'Cash drop'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('cash_in_safe'), 10000, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('cash'), 0, 10000, null, null),
            ],
            sessionId: $sessionId,
        ));
    }

    private function payCart(string $sessionId, string $date, bool $card, ?string $check): void
    {
        $cart = $this->tickets->cart($sessionId);
        $due = $cart['due'];
        $roundOn = !$card && $check === null && $this->roundCash ? 1 : 0;
        $account = $check !== null ? 'checks' : ($card ? 'card' : 'cash');
        $cash = $card || $check !== null ? 0 : $due;
        $this->poster->pay($sessionId, $date, $roundOn, $cart, [
            'lines' => [PostedLines::entry($this->ledger->roleAccount($account), $due, 0, null, null)],
            'sum' => $due,
            'check' => $check,
            'cash' => $cash,
        ], $cash > 0 ? ($roundOn === 1 ? CashRound::rounded($due) : $due) : null);
    }

    private function shelfBarcode(string $vendor): string
    {
        $rows = $this->statements->bound(
            'SELECT item.barcode
             FROM items AS item
             WHERE item.vendor_id = ?::uuid
               AND item.quantity > 0
               AND NOT EXISTS (SELECT 1 FROM ticket_lines AS line WHERE line.item_id = item.id)
             ORDER BY item.code DESC
             LIMIT 1',
            [$this->vendors[$vendor]]
        );
        $barcode = $rows[0]['barcode'] ?? null;
        if (!is_string($barcode) || $barcode === '') {
            throw new RuntimeException($vendor . ' has no shelf item left');
        }

        return $barcode;
    }

    private function waitList(): void
    {
        $people = [
            ['Nina', 'Cole', 'nina@example.com', '555-0160', 10, 10, 'waiting', 'Wants a corner booth.', '2026-08-12 15:00:00-07'],
            ['Omar', 'Diaz', 'omar@example.com', '555-0161', 8, 10, 'waiting', 'Called once. Still waiting.', '2026-09-02 15:00:00-07'],
            ['Priya', 'Shah', 'priya@example.com', '555-0162', 10, 10, 'contacted', 'Tour scheduled for October.', '2026-09-18 15:00:00-07'],
            ['Ruth', 'Kim', 'ruth@example.com', '555-0163', null, null, 'placed', 'Placed on a booth earlier in the year.', '2026-04-20 15:00:00-07'],
            ['Sam', 'Ito', 'sam@example.com', '555-0164', 12, 12, 'declined', 'Needed a larger booth than we have.', '2026-06-01 15:00:00-07'],
        ];
        foreach ($people as $person) {
            $this->statements->change(
                'INSERT INTO wait_list (first_name, last_name, email, phone, width_ft, depth_ft, status, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz)',
                $person
            );
        }
    }

    private function sideDoor(): void
    {
        $register = $this->ledger->newId();
        $this->statements->change(
            'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
            [$register, 'Side door']
        );
        $session = $this->ledger->newId();
        $zero = DenominationCount::json(DenominationCount::forAmount(0));
        $this->statements->change(
            'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor, opened_at)
             VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0, ?::timestamptz)',
            [$session, $register, 'Nora Hale', $zero, '2026-05-02 10:00:00-07']
        );
        $closed = $this->statements->affected(
            'UPDATE register_sessions
             SET closing_counts = ?::jsonb, counted_cash_minor = 0, counted_card_minor = 0, counted_checks_minor = 0, closed_at = ?::timestamptz
             WHERE id = ?::uuid AND closed_at IS NULL',
            [$zero, '2026-05-02 16:00:00-07', $session]
        );
        if ($closed !== 1) {
            throw new RuntimeException('the side door did not close');
        }
    }
}
