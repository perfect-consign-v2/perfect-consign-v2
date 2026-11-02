<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\PosCartView;
use PerfectConsign\Feature\Pos\PosRegister;
use PerfectConsign\Feature\Pos\RegisterGate;
use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Feature\Pos\SaleHold;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\SaleReversal;
use PerfectConsign\Feature\Pos\SaleTicketAttach;
use PerfectConsign\Feature\Pos\SnapSync;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Pos\TicketTenders;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use PDOStatement;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class PosPagePayCartTest extends TestCase
{
    public function testPayCartMintsKeysForAPayableOpenTicket(): void
    {
        $cart = $this->mintingView(false)->payCart('session-1', [
            'ticket_id' => 'ticket-1',
            'lines' => [['line_no' => 1]],
        ], '');

        self::assertSame(['01900000-0000-7000-8000-00000000bb01'], $cart['snap_keys']);
    }

    public function testPayCartSkipsMintWhenTheSaleIsHeldFinished(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects(self::never())->method('bound');
        $cart = $this->view($this->register($statements), false)->payCart('session-1', [
            'ticket_id' => 'ticket-1',
            'lines' => [['line_no' => 1]],
        ], 'journal-1');

        self::assertSame([], $cart['snap_keys']);
    }

    public function testSalePanelEmbedsMintedSnapKeys(): void
    {
        $panel = $this->mintingView(true)->salePanel('demo', 'session-1', [
            'ticket_id' => 'ticket-1',
            'buyer_id' => '',
            'due' => 100,
            'lines' => [['line_no' => 1]],
        ], [], [], 0, '');

        self::assertSame(['01900000-0000-7000-8000-00000000bb01'], $panel['cart']['snap_keys']);
        self::assertSame(100, $panel['remaining']);
        self::assertSame('', $panel['shopperId']);
        self::assertSame(0, $panel['salesAvailable']);
        self::assertSame('', $panel['giftNumber']);
        self::assertNull($panel['giftBalance']);
        self::assertNull($panel['change']);
    }

    public function testSalePanelSubtractsAppliedTendersFromDue(): void
    {
        $panel = $this->mintingView(true)->salePanel('demo', 'session-1', [
            'ticket_id' => 'ticket-1',
            'buyer_id' => '',
            'due' => 500,
            'lines' => [['line_no' => 1]],
        ], [], [], 125, '');

        self::assertSame(375, $panel['remaining']);
    }

    public function testChromeNamesTheSellScreen(): void
    {
        $view = $this->view($this->register($this->createStub(Statements::class)), true);
        $chrome = $view->chrome(new TenantRecord('id', 'demo', 'schema_demo', 'owner', 'Demo Mall'), null);
        self::assertSame('Sell', $chrome['title']);
        self::assertSame('demo', $chrome['slug']);
        self::assertSame('Demo Mall', $chrome['displayName']);
        self::assertFalse($chrome['instantAdded']);
        self::assertFalse($chrome['instantAddedPage']);
    }

    public function testChromeSeesInstantAddFlagsFromTheQuery(): void
    {
        $view = $this->view($this->register($this->createStub(Statements::class)), true, [
            'added' => '1',
            'instant_added' => '1',
        ]);
        $chrome = $view->chrome(new TenantRecord('id', 'demo', 'schema_demo', 'owner', 'Demo Mall'), null);
        self::assertTrue($chrome['instantAdded']);
        self::assertTrue($chrome['instantAddedPage']);
    }

    public function testChromeTreatsOnlyExactOneAsInstantAdd(): void
    {
        $view = $this->view($this->register($this->createStub(Statements::class)), true, [
            'added' => '0',
            'instant_added' => '2',
        ]);
        $chrome = $view->chrome(new TenantRecord('id', 'demo', 'schema_demo', 'owner', 'Demo Mall'), null);
        self::assertFalse($chrome['instantAdded']);
        self::assertFalse($chrome['instantAddedPage']);
    }

    public function testSalePanelLooksUpGiftBalanceFromTheQuery(): void
    {
        $statements = $this->panelStatements(
            static function (string $sql): ?array {
                if (str_contains($sql, 'stored_values')) {
                    return [['balance' => 2500]];
                }

                return null;
            }
        );
        $view = $this->view($this->register($statements), true, ['gift' => 'GC-100'], [
            'gifts' => new GiftIssue($statements, $this->createStub(LedgerStore::class)),
        ]);
        $panel = $view->salePanel('demo', 'session-1', [
            'ticket_id' => 'ticket-1',
            'buyer_id' => '',
            'due' => 0,
            'lines' => [],
        ], [], [], 0, '');

        self::assertSame('GC-100', $panel['giftNumber']);
        self::assertSame(2500, $panel['giftBalance']);
    }

    public function testSalePanelUsesOpenPayoutWhenTheBuyerIsKnown(): void
    {
        $payable = $this->createStub(PDOStatement::class);
        $payable->method('fetchColumn')->willReturn(4200);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($payable);
        $view = $this->view($this->register($this->panelStatements()), true, [], [
            'payouts' => new PayoutStore(
                $pdo,
                $this->createStub(Statements::class),
                (new ReflectionClass(PayoutRunStore::class))->newInstanceWithoutConstructor(),
                new PayoutWindow(),
            ),
        ]);
        $panel = $view->salePanel('demo', 'session-1', [
            'ticket_id' => 'ticket-1',
            'buyer_id' => 'vendor-1',
            'due' => 100,
            'lines' => [],
        ], [], [], 0, '');

        self::assertSame(4200, $panel['salesAvailable']);
    }

    public function testSalePanelChangeIsCashHandedMinusReceiptCash(): void
    {
        $journalId = '01900000-0000-7000-8000-0000000000j1';
        $statements = $this->panelStatements(
            static function (string $sql) use ($journalId): ?array {
                if (str_contains($sql, 'journal_lines') && str_contains($sql, 'accounts AS account')) {
                    return [[
                        'name' => 'Cash',
                        'debit_minor' => 1500,
                        'credit_minor' => 0,
                        'role' => 'cash',
                    ]];
                }

                return null;
            }
        );
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_type' => 'sale',
            'posting_key' => 'key',
            'journal_no' => 1,
            'posting_date' => '2026-01-01',
            'description' => 'Sale',
        ]);
        $ledger->method('reversed')->willReturn(false);
        $poster = new SalePoster(
            $ledger,
            new SaleReceipt($ledger, $statements),
            new ReceiptChange(),
            (new ReflectionClass(SaleTicketAttach::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SaleReversal::class))->newInstanceWithoutConstructor(),
        );
        $view = $this->view($this->register($statements), true, [], [
            'sales' => new SaleHold(new Session(['sale_tendered:demo' => '20.00'], false)),
            'poster' => $poster,
        ]);
        $panel = $view->salePanel('demo', 'session-1', [
            'ticket_id' => 'ticket-1',
            'buyer_id' => '',
            'due' => 0,
            'lines' => [],
        ], [], [], 0, $journalId);

        self::assertSame(500, $panel['change']);
        self::assertSame(1500, $panel['completed']['cash']);
    }

    /**
     * @param callable(string, list<mixed>): (?array<int, array<string, mixed>>)|null $extra
     */
    private function panelStatements(?callable $extra = null): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(
            static function (string $sql, array $params = []) use ($extra): array {
                if ($extra !== null) {
                    $matched = $extra($sql, $params);
                    if ($matched !== null) {
                        return $matched;
                    }
                }
                if (str_contains($sql, "role.role = 'layaway_deposit'")) {
                    return [['balance' => 0]];
                }
                if (str_contains($sql, 'to_char(journal.posting_date')) {
                    return [];
                }
                if (str_contains($sql, 'tickets AS ticket')) {
                    return [];
                }

                return [];
            }
        );

        return $statements;
    }

    private function mintingView(bool $withApp): PosCartView
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'stall_mint_register_snaps')) {
                    return [['posting_key' => '01900000-0000-7000-8000-00000000bb01']];
                }
                if (str_contains($sql, 'COALESCE(sum(line.credit_minor - line.debit_minor), 0)')) {
                    return [['balance' => 0]];
                }

                return [];
            }
        );

        return $this->view($this->register($statements), $withApp);
    }

    /**
     * @param array<string, string> $query
     * @param array{gifts?: GiftIssue, sales?: SaleHold, poster?: SalePoster, payouts?: PayoutStore} $panelDeps
     */
    private function view(PosRegister $register, bool $withApp, array $query = [], array $panelDeps = []): PosCartView
    {
        $view = (new ReflectionClass(PosCartView::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PosCartView::class, 'register'))->setValue($view, $register);
        if ($withApp) {
            $app = (new ReflectionClass(MallApp::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(MallApp::class, 'request'))->setValue(
                $app,
                new Request('GET', '/demo/pos', $query, [])
            );
            (new ReflectionProperty(PosCartView::class, 'app'))->setValue($view, $app);
            (new ReflectionProperty(PosCartView::class, 'gifts'))->setValue(
                $view,
                $panelDeps['gifts'] ?? (new ReflectionClass(GiftIssue::class))->newInstanceWithoutConstructor()
            );
            if (isset($panelDeps['sales'])) {
                (new ReflectionProperty(PosCartView::class, 'sales'))->setValue($view, $panelDeps['sales']);
            } else {
                $sales = (new ReflectionClass(SaleHold::class))->newInstanceWithoutConstructor();
                (new ReflectionProperty(SaleHold::class, 'session'))->setValue(
                    $sales,
                    new Session([], false)
                );
                (new ReflectionProperty(PosCartView::class, 'sales'))->setValue($view, $sales);
            }
            (new ReflectionProperty(PosCartView::class, 'poster'))->setValue(
                $view,
                $panelDeps['poster'] ?? (new ReflectionClass(SalePoster::class))->newInstanceWithoutConstructor()
            );
            (new ReflectionProperty(PosCartView::class, 'payouts'))->setValue(
                $view,
                $panelDeps['payouts'] ?? (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor()
            );
        }

        return $view;
    }

    private function register(Statements $statements): PosRegister
    {
        $register = (new ReflectionClass(PosRegister::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PosRegister::class, 'snaps'))->setValue($register, new SnapSync($statements));
        $tickets = (new ReflectionClass(TicketStore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TicketStore::class, 'statements'))->setValue($tickets, $statements);
        $customers = (new ReflectionClass(TicketCustomer::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TicketCustomer::class, 'statements'))->setValue($customers, $statements);
        (new ReflectionProperty(TicketStore::class, 'customers'))->setValue($tickets, $customers);
        (new ReflectionProperty(PosRegister::class, 'tickets'))->setValue($register, $tickets);
        (new ReflectionProperty(PosRegister::class, 'held'))->setValue(
            $register,
            (new ReflectionClass(TicketTenders::class))->newInstanceWithoutConstructor()
        );
        (new ReflectionProperty(PosRegister::class, 'drafts'))->setValue(
            $register,
            (new ReflectionClass(TicketDrafts::class))->newInstanceWithoutConstructor()
        );
        (new ReflectionProperty(PosRegister::class, 'gate'))->setValue(
            $register,
            (new ReflectionClass(RegisterGate::class))->newInstanceWithoutConstructor()
        );
        $layaways = (new ReflectionClass(LayawayBook::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(LayawayBook::class, 'statements'))->setValue($layaways, $statements);
        (new ReflectionProperty(PosRegister::class, 'layaways'))->setValue($register, $layaways);

        return $register;
    }
}
