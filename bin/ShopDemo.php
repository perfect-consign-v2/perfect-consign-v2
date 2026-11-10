<?php

declare(strict_types=1);

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyDraft;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutRunChecks;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Offer\OfferStore;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Pos\TicketBuyer;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketPriceChange;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Feature\Pos\TicketQueue;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ShopDemo
{
    private const REGISTER = '01900000-0000-7000-8000-000000000d10';

    /** @var list<int> */
    private const BASKET = [18000, 12000, 9500, 7000];

    /** @var list<int> */
    private const SHELF = [2400, 4500, 8900, 15000];

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
    ];

    /** @var list<array{name: string, phone: string, email: string}> */
    private const SHOPPERS = [
        ['name' => 'Helen Ward', 'phone' => '555-0201', 'email' => 'helen@example.com'],
        ['name' => 'Marcus Lee', 'phone' => '555-0202', 'email' => 'marcus@example.com'],
        ['name' => 'Sofia Reyes', 'phone' => '555-0203', 'email' => 'sofia@example.com'],
        ['name' => 'Tom Briggs', 'phone' => '555-0204', 'email' => 'tom@example.com'],
        ['name' => 'Amy Cho', 'phone' => '555-0205', 'email' => 'amy@example.com'],
        ['name' => 'Paul Rivera', 'phone' => '555-0206', 'email' => 'paul@example.com'],
        ['name' => 'Grace Kim', 'phone' => '555-0207', 'email' => 'grace@example.com'],
        ['name' => 'Owen Blake', 'phone' => '555-0208', 'email' => 'owen@example.com'],
    ];

    private readonly Statements $statements;

    private readonly LedgerStore $ledger;

    private readonly PartyStore $parties;

    private readonly TicketStore $tickets;

    private readonly TicketCustomer $shoppers;

    private readonly CustomerStore $customers;

    private readonly OfferStore $offers;

    private readonly SalePoster $poster;

    private readonly CompanyStore $company;

    private readonly PayoutRunStore $runs;

    private readonly PayoutSettler $settler;

    private readonly PayoutDisburser $disburser;

    /** @var array<string, string> */
    private array $vendors = [];

    /** @var array<string, list<array{id: string, barcode: string}>> */
    private array $queues = [];

    /** @var list<array{journal: string, item: string, due: int, date: string, vendor: string}> */
    private array $sales = [];

    /** @var list<string> */
    private array $brands = [];

    private int $itemNo = 0;

    private int $shopperNo = 0;

    private bool $roundCash = false;

    public function __construct(private readonly PDO $pdo, private readonly string $schema)
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new InvalidArgumentException('refusing an unexpected schema');
        }
        $this->statements = new Statements($pdo);
        $this->ledger = new LedgerStore($pdo, $this->statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $journalAudit = new \PerfectConsign\Bootstrap\JournalAudit(
            new \PerfectConsign\Bootstrap\Actor(new \PerfectApp\Session\Session(null, false)),
            new \PerfectConsign\Bootstrap\AuditLog($this->statements),
        );
        $stays = new \PerfectConsign\Feature\Party\AssignmentBook($this->statements);
        $this->parties = new PartyStore(
            $this->statements,
            $journalAudit,
            $stays,
            new \PerfectConsign\Feature\Party\PartyIdentity($this->statements),
        );
        $pricing = new TicketPricing($this->statements);
        $actor = new \PerfectConsign\Bootstrap\Actor(new \PerfectApp\Session\Session(null, false));
        $auditLog = new \PerfectConsign\Bootstrap\AuditLog($this->statements);
        $ceiling = new \PerfectConsign\Bootstrap\SaleCeiling(
            $this->statements,
            $actor,
            $auditLog,
            new \PerfectConsign\Shared\Http\Request('GET', '/', [], []),
        );
        $identity = new \PerfectConsign\Feature\Party\PartyIdentity($this->statements);
        $this->customers = new CustomerStore($this->statements, $identity);
        $this->shoppers = new TicketCustomer($this->statements, $this->customers);
        $this->offers = new OfferStore($this->statements);
        $this->company = new CompanyStore($this->statements, new DatabaseRole($pdo), $pdo, new CompanyLines());
        $mall = new \PerfectConsign\Feature\Mall\MallSettings(
            $this->statements,
            $this->company,
            new \PerfectConsign\Feature\Mall\MallDay($this->statements),
        );
        $this->tickets = new TicketStore(
            $this->statements,
            $pricing,
            new TicketBuyer($this->statements, $mall),
            new TicketQueue($this->statements, $pricing),
            $this->shoppers,
            $ceiling,
            new TicketPriceChange($this->statements, $pricing, $actor, $auditLog),
        );
        $attach = new \PerfectConsign\Feature\Pos\SaleTicketAttach($this->statements);
        $reversals = new \PerfectConsign\Feature\Pos\SaleReversal(
            $this->ledger,
            $this->statements,
            $ceiling,
            new \PerfectConsign\Feature\Payout\VendorClawback($this->ledger, $this->statements),
        );
        $this->poster = new SalePoster(
            $this->ledger,
            new SaleReceipt($this->ledger, $this->statements),
            new ReceiptChange(),
            $attach,
            $reversals,
        );
        $this->runs = new PayoutRunStore($this->statements, new OccupiedPack($this->statements));
        $payouts = new PayoutStore($pdo, $this->statements, $this->runs, new PayoutWindow());
        $lines = new PayoutRunLines($this->statements);
        $this->settler = new PayoutSettler(
            $this->ledger,
            $payouts,
            $this->runs,
            $lines,
            $journalAudit,
            new \PerfectConsign\Feature\Payout\VendorClawback($this->ledger, $this->statements),
        );
        $this->disburser = new PayoutDisburser(
            $this->ledger,
            $payouts,
            $this->runs,
            $lines,
            new PayoutRunChecks($this->statements),
            $journalAudit,
        );
    }

    public function load(): void
    {
        $this->useShop();
        $this->companyProfile();
        $this->statements->change(
            'UPDATE mall SET commission_bps = ?, tax_bps = ?, floor_days = ?',
            [2500, 8750, 90]
        );
        \PerfectConsign\Shared\Mail\MailboxSeed::plant($this->pdo);
        $this->statements->change('UPDATE registers SET name = ? WHERE id = ?::uuid', ['Front desk', self::REGISTER]);
        $this->brands();
        $this->people();
        $this->stock('ADA', '2026-08-01', 10);
        $this->stock('BEA', '2026-08-01', 10);
        $this->stock('CAL', '2026-08-01', 10);
        $this->stock('DAN', '2026-08-01', 8);
        $this->stock('IRI', '2026-08-01', 4);
        $this->stock('JON', '2026-08-01', 4);
        $this->itemNotes();
        $this->directory();
        $this->deals();
        $this->opening();
        $this->month('2026-08');
        $this->month('2026-09');
        $this->october();
        $this->note("shop demo posted through 2026-10-05\n");
    }

    private function note(string $line): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $line);
            fflush(STDOUT);
        }
    }

    private function useShop(): void
    {
        $this->pdo->exec('SET search_path TO ' . $this->schema . ', pg_catalog, extensions');
    }

    private function companyProfile(): void
    {
        $rows = $this->statements->bound(
            'SELECT id, slug, schema_name, display_name FROM platform.tenants WHERE schema_name = ?',
            [$this->schema]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new RuntimeException('the shop tenant is missing');
        }
        $slug = RowValue::string($row['slug'] ?? null);
        $schema = RowValue::string($row['schema_name'] ?? null);
        $this->company->save(new TenantRecord(
            RowValue::string($row['id'] ?? null),
            $slug,
            $schema,
            TenantRecord::roleFor($slug, $schema),
            RowValue::string($row['display_name'] ?? null),
        ), CompanyDraft::parse([
            'name' => 'Cedar Consignment',
            'street' => '88 Maple Avenue',
            'street2' => '',
            'city' => 'Bend',
            'state' => 'OR',
            'postal' => '97701',
            'phone' => '541-555-0188',
            'email' => 'desk@cedar-consign.test',
        ]));
        if ($this->pdo->exec('RESET ROLE') === false) {
            throw new RuntimeException('the database role could not be reset');
        }
        $this->useShop();
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

    private function people(): void
    {
        $this->vendor('ADA', 'Ada', 'Cole', '555-0140', 'ada.cole@example.com', '14 Cedar St', 2000, '2026-08-01', '', 'Store keeps 20 percent.');
        $this->vendor('BEA', 'Bea', 'Ruiz', '555-0141', 'bea.ruiz@example.com', '8 Harbor Ave', 1500, '2026-08-01', '', 'Store keeps 15 percent.');
        $this->vendor('CAL', 'Cal', 'Nguyen', '555-0144', 'cal@example.com', '31 Pine Rd', 2500, '2026-08-01', '', 'Store keeps 25 percent.');
        $this->vendor('DAN', 'Dana', 'Shah', '555-0146', 'dana@example.com', '2 Elm Ct', 2000, '2026-08-01', '2026-09-30', 'Left at the end of September.');
        $this->vendor('IRI', 'Iris', 'Chen', '555-0154', 'iris@example.com', '3 Lake Dr', 2000, '2026-08-01', '', 'Resale certificate on file.');
        $this->vendor('JON', 'Jon', 'Hale', '555-0156', 'jon@example.com', '18 River Rd', 2000, '2026-08-01', '', 'Payouts are on hold.');
        $this->statements->change(
            'UPDATE vendors SET tax_exempt = true, tax_exempt_number = ? WHERE id = ?::uuid',
            ['RESALE-1042', $this->vendors['IRI']]
        );
        $this->statements->change(
            'UPDATE vendors SET payout_mode = ? WHERE id = ?::uuid',
            ['hold', $this->vendors['JON']]
        );
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

    private function stock(string $vendor, string $intake, int $sellable): void
    {
        $brand = 0;
        for ($index = 0; $index < $sellable + count(self::SHELF); $index++) {
            $this->itemNo++;
            $id = $this->ledger->newId();
            $price = $index < $sellable ? self::BASKET[$index % count(self::BASKET)] : self::SHELF[$index - $sellable];
            $barcode = 'SC' . str_pad((string) $this->itemNo, 12, '0', STR_PAD_LEFT);
            $code = $vendor . '-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $this->statements->change(
                'INSERT INTO items (id, code, vendor_id, brand_id, description, price_minor, barcode, intake_on, quantity, placement)
                 VALUES (?::uuid, ?, ?::uuid, ?::uuid, ?, ?, ?, ?::date, 1, ?)',
                [
                    $id,
                    $code,
                    $this->vendors[$vendor],
                    $this->brands[$brand % count($this->brands)],
                    self::GOODS[$this->itemNo % count(self::GOODS)],
                    $price,
                    $barcode,
                    $intake,
                    'consigned',
                ]
            );
            $brand++;
            if ($index < $sellable) {
                $this->queues[$vendor][] = ['id' => $id, 'barcode' => $barcode];
            }
        }
    }

    private function itemNotes(): void
    {
        $this->statements->change(
            'UPDATE items SET quantity = 3 WHERE vendor_id = ?::uuid AND code = ?',
            [$this->vendors['ADA'], 'ADA-011']
        );
        $this->statements->change(
            'UPDATE items SET expires_on = ?::date WHERE vendor_id = ?::uuid AND code = ?',
            ['2026-12-15', $this->vendors['BEA'], 'BEA-011']
        );
        $this->statements->change(
            'UPDATE items SET commission_bps = ? WHERE vendor_id = ?::uuid AND code = ?',
            [1000, $this->vendors['CAL'], 'CAL-011']
        );
    }

    private function directory(): void
    {
        foreach (self::SHOPPERS as $shopper) {
            $this->customers->findOrCreate($shopper['name'], $shopper['phone'], $shopper['email']);
        }
        $this->customers->findOrCreate('Rita Moss', '555-0210', 'rita@example.com');
        $this->customers->findOrCreate('Ken Hale', '555-0211', '');
        $this->customers->findOrCreate('Julia Frost', '', 'julia@example.com');
    }

    private function deals(): void
    {
        $this->offers->save(null, [
            'name' => 'Store three for $25',
            'scope' => 'store',
            'kind' => 'bundle',
            'threshold' => '3',
            'price' => '25.00',
            'funded_by' => 'store',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-10-31',
            'item_code' => '',
            'vendor_id' => '',
        ], null);
        $this->offers->save(null, [
            'name' => 'Ada buy-two',
            'scope' => 'vendor',
            'kind' => 'unit_break',
            'threshold' => '2',
            'price' => '12.00',
            'funded_by' => 'vendor',
            'starts_on' => '2026-08-01',
            'ends_on' => '',
            'item_code' => '',
            'vendor_id' => $this->vendors['ADA'],
        ], null);
        $this->offers->save(null, [
            'name' => 'Quilt pair deal',
            'scope' => 'item',
            'kind' => 'bundle',
            'threshold' => '2',
            'price' => '30.00',
            'funded_by' => 'vendor',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-12-31',
            'item_code' => 'ADA-011',
            'vendor_id' => '',
        ], null);
        $this->offers->save(null, [
            'name' => 'Summer clear-out',
            'scope' => 'store',
            'kind' => 'bundle',
            'threshold' => '4',
            'price' => '40.00',
            'funded_by' => 'store',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-07-31',
            'item_code' => '',
            'vendor_id' => '',
        ], null);
    }

    private function opening(): void
    {
        $this->ledger->post(new JournalDraft(
            postingKey: 'opening',
            postingDate: '2026-07-31',
            source: new JournalSource('opening', 'opening', 'Opening balance'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('bank'), 500000, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('retained_earnings'), 0, 500000, null, null),
            ],
        ));
    }

    private function month(string $month): void
    {
        $this->useShop();
        $this->note($month . "\n");
        $session = $this->openDrawer($month . '-01');
        $who = ['ADA', 'BEA', 'CAL', 'DAN'];
        if ($month === '2026-08') {
            $who[] = 'IRI';
            $who[] = 'JON';
        }
        foreach ($who as $code) {
            $days = [8, 16, 22, 27];
            if ($code === 'IRI' || $code === 'JON') {
                $days = [10, 20];
            }
            foreach ($days as $day) {
                $this->sell($session, $code, $month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT), false);
            }
        }
        if ($month === '2026-09') {
            $this->giveBack($session, '2026-09-18', $this->findSale('BEA', '2026-09'));
            $voided = $this->findSale('ADA', '2026-09');
            $this->poster->voidSale($session, $voided['date'], $voided['journal']);
        }
        $this->closeDrawer($session, $month, 0);
        $next = (new DateTimeImmutable($month . '-01'))->modify('first day of next month')->format('Y-m-d');
        $this->payout($next);
        if ($month === '2026-09') {
            $this->parties->setActive($this->vendors['DAN'], false);
        }
    }

    private function october(): void
    {
        $this->useShop();
        $this->note("2026-10\n");
        $this->roundCash = true;
        $this->statements->change('UPDATE mall SET cash_round_minor = 5', []);
        $session = $this->openDrawer('2026-10-01');
        foreach (['ADA', 'BEA', 'CAL'] as $code) {
            $this->sell($session, $code, '2026-10-02', false);
            $this->sell($session, $code, '2026-10-04', $code === 'ADA');
        }
        $this->counter($session);
        $this->note("  October payout left unpaid\n");
    }

    private function counter(string $sessionId): void
    {
        $this->tickets->scan($sessionId, $this->shelfBarcode('BEA'));
        $this->tickets->discount($sessionId, 'amount', 500, 'damaged');
        $this->nameShopper($this->tickets->cart($sessionId)['ticket_id']);
        $this->payCart($sessionId, '2026-10-03', false, null);
        $this->tickets->scan($sessionId, $this->shelfBarcode('CAL'));
        $this->tickets->waive($sessionId, 'resale');
        $this->nameShopper($this->tickets->cart($sessionId)['ticket_id']);
        $this->payCart($sessionId, '2026-10-03', false, '4402');
        $this->tickets->scan($sessionId, $this->shelfBarcode('IRI'));
        $cart = $this->tickets->cart($sessionId);
        $this->nameShopper($cart['ticket_id']);
        (new LayawayBook($this->statements, $this->ledger))->hold($sessionId, '2026-10-03', $cart['ticket_id'], $cart['due'], [
            'lines' => [PostedLines::entry($this->ledger->roleAccount('cash'), 2000, 0, null, null)],
            'sum' => 2000,
            'check' => null,
            'cash' => 2000,
        ]);
        $this->tickets->scan($sessionId, $this->shelfBarcode('ADA'));
        $this->nameShopper($this->tickets->cart($sessionId)['ticket_id']);
        $this->tickets->park($sessionId, '');
        (new GiftIssue($this->statements, $this->ledger))->issue(
            $sessionId,
            '2026-10-03',
            'GC-CEDAR7NP4RX',
            2500,
            'cash',
            null,
            'gift_certificate',
            '01900000000070008000000000c0ffee',
        );
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

    private function closeDrawer(string $sessionId, string $month): void
    {
        $end = (new DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');
        $rows = $this->statements->bound('SELECT stall_register_expected_cash(?::uuid) AS cash', [$sessionId]);
        $expected = RowValue::integer($rows[0]['cash'] ?? null);
        $updated = $this->statements->affected(
            'UPDATE register_sessions
             SET closing_counts = ?::jsonb, counted_cash_minor = ?, counted_card_minor = 0, counted_checks_minor = 0, closed_at = ?::timestamptz
             WHERE id = ?::uuid AND closed_at IS NULL',
            [DenominationCount::json(DenominationCount::forAmount($expected)), $expected, $end . ' 18:00:00-07', $sessionId]
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
        $this->nameShopper($cart['ticket_id']);
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

    private function payout(string $postingDate): void
    {
        $this->useShop();
        $window = (new PayoutWindow())->window($postingDate);
        $runId = $this->runs->ensureDraft($window['from'], $window['to'], $window['rent'], $postingDate);
        $this->runs->review($runId);
        $this->settler->close($runId, $postingDate);
        $this->disburser->pay($runId, $postingDate);
        $this->note('  payout ' . $window['from'] . ' through ' . $window['to'] . ' posted ' . $postingDate . "\n");
    }

    private function nameShopper(string $ticketId): void
    {
        if ($ticketId === '' || $this->shopperNo % 3 === 2) {
            $this->shopperNo++;

            return;
        }
        $shopper = self::SHOPPERS[$this->shopperNo % count(self::SHOPPERS)];
        $this->shopperNo++;
        $this->shoppers->save($ticketId, $shopper['name'], $shopper['phone'], $shopper['email']);
    }

    private function payCart(string $sessionId, string $date, bool $card, ?string $check): void
    {
        $cart = $this->tickets->cart($sessionId);
        $due = $cart['due'];
        $roundOn = !$card && $check === null && $this->roundCash ? 5 : 0;
        $account = $check !== null ? 'checks' : ($card ? 'card' : 'cash');
        $cash = $card || $check !== null ? 0 : $due;
        $this->poster->pay($sessionId, $date, $roundOn, $cart, [
            'lines' => [PostedLines::entry($this->ledger->roleAccount($account), $due, 0, null, null)],
            'sum' => $due,
            'check' => $check,
            'cash' => $cash,
        ], $cash > 0 ? ($roundOn >= 1 ? CashRound::rounded($due, $roundOn) : $due) : null);
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
}
