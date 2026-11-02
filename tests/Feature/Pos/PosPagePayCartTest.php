<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\PosCartView;
use PerfectConsign\Feature\Pos\PosRegister;
use PerfectConsign\Feature\Pos\RegisterGate;
use PerfectConsign\Feature\Pos\SaleHold;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SnapSync;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Pos\TicketTenders;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Shared\Database\Statements;
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
    }

    public function testChromeNamesTheSellScreen(): void
    {
        $view = $this->view($this->register($this->createStub(Statements::class)), true);
        $chrome = $view->chrome(new TenantRecord('id', 'demo', 'schema_demo', 'owner', 'Demo Mall'), null);
        self::assertSame('Sell', $chrome['title']);
        self::assertSame('demo', $chrome['slug']);
        self::assertSame('Demo Mall', $chrome['displayName']);
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

    private function view(PosRegister $register, bool $withApp): PosCartView
    {
        $view = (new ReflectionClass(PosCartView::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PosCartView::class, 'register'))->setValue($view, $register);
        if ($withApp) {
            $app = (new ReflectionClass(MallApp::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(MallApp::class, 'request'))->setValue(
                $app,
                new Request('GET', '/demo/pos', [], [])
            );
            (new ReflectionProperty(PosCartView::class, 'app'))->setValue($view, $app);
            (new ReflectionProperty(PosCartView::class, 'gifts'))->setValue(
                $view,
                (new ReflectionClass(GiftIssue::class))->newInstanceWithoutConstructor()
            );
            $sales = (new ReflectionClass(SaleHold::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(SaleHold::class, 'session'))->setValue(
                $sales,
                new Session([], false)
            );
            (new ReflectionProperty(PosCartView::class, 'sales'))->setValue($view, $sales);
            (new ReflectionProperty(PosCartView::class, 'poster'))->setValue(
                $view,
                (new ReflectionClass(SalePoster::class))->newInstanceWithoutConstructor()
            );
            (new ReflectionProperty(PosCartView::class, 'payouts'))->setValue(
                $view,
                (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor()
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
