<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\PosCartView;
use PerfectConsign\Feature\Pos\PosPage;
use PerfectConsign\Feature\Pos\PosRegister;
use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Feature\Pos\RegisterGate;
use PerfectConsign\Feature\Pos\SaleHold;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\SaleReversal;
use PerfectConsign\Feature\Pos\SaleTicketAttach;
use PerfectConsign\Feature\Pos\SnapSync;
use PerfectConsign\Feature\Pos\TicketBuyer;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Feature\Pos\TicketLines;
use PerfectConsign\Feature\Pos\TicketPriceChange;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Feature\Pos\TicketQueue;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Pos\TicketTenders;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

final class PosPageGapTest extends TestCase
{
    use CapturesPage;

    public function testAnInstantErrorRepaintsTheFormWithThePostedFields(): void
    {
        $known = $this->page($this->fields('percent'));
        $html = $this->capture(function () use ($known): void {
            $known->instant(MallDoor::SLUG, 'Enter a price.');
        });
        self::assertStringContainsString('Enter a price.', $html);
        self::assertStringContainsString('Wool hat', $html);
        self::assertStringContainsString('value="percent" selected', $html);

        $fallback = $this->page($this->fields('bogus'));
        $html = $this->capture(function () use ($fallback): void {
            $fallback->instant(MallDoor::SLUG, 'Enter a description.');
        });
        self::assertStringContainsString('Enter a description.', $html);
        self::assertStringContainsString('value="consignor_default" selected', $html);
    }

    /**
     * @param array<string, string> $post
     */
    private function page(array $post): PosPage
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/pos', ['instant' => '1'], $post));
        $statements = $door->statements;
        $pricing = new TicketPricing($statements);
        $ceiling = new SaleCeiling($statements, $door->actor, new AuditLog($statements), $door->app->request());
        $tickets = new TicketStore(
            $statements,
            $pricing,
            new TicketBuyer($statements, $door->mall),
            new TicketQueue($statements, $pricing),
            new TicketCustomer($statements, new CustomerStore($statements, new PartyIdentity($statements))),
            $ceiling,
            new TicketPriceChange($statements, $pricing, $door->actor, new AuditLog($statements)),
        );
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $payouts = new PayoutStore($door->pdo, $statements, $runs, new PayoutWindow());
        $clock = new MallClock($door->mall);
        $register = new PosRegister(
            $tickets,
            new TicketTenders(
                $statements,
                $door->ledger,
                new RegisterStore($statements, $clock, new RegisterActivity($statements, $clock)),
                $payouts,
                $door->mall,
            ),
            new TicketDrafts($statements),
            new RegisterGate($door->app, $door->register, $tickets),
            new LayawayBook($statements, $door->ledger),
            new SnapSync($statements),
        );
        $hold = new SaleHold($door->session);
        $view = new PosCartView(
            $door->app,
            $register,
            new GiftIssue($statements, $door->ledger),
            $hold,
            new SalePoster(
                $door->ledger,
                new SaleReceipt($door->ledger, $statements),
                new ReceiptChange(),
                new SaleTicketAttach($statements),
                new SaleReversal($door->ledger, $statements, $ceiling, new VendorClawback($door->ledger, $statements)),
            ),
            $payouts,
        );

        return new PosPage($door->app, $register, $view, $hold);
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $kind): array
    {
        return [
            'vendor_id' => MallDoor::VENDOR,
            'vendor_label' => 'Ada',
            'description' => 'Wool hat',
            'price' => '12.00',
            'discount_kind' => $kind,
            'discount_value' => '5',
            'brand_id' => '',
            'brand_label' => 'Wool',
            'color_id' => MallDoor::PARTY,
            'color_label' => 'Blue',
            'category_id' => '',
            'category_label' => '',
            'size_id' => '',
            'size_label' => '',
            'condition_id' => '',
            'condition_label' => '',
            'department_id' => '',
            'department_label' => '',
        ];
    }
}
