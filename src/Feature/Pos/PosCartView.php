<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class PosCartView
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PosRegister $register,
        private readonly GiftIssue $gifts,
        private readonly SaleHold $sales,
        private readonly SalePoster $poster,
        private readonly PayoutStore $payouts,
    ) {
    }

    /**
     * @param array{ticket_id: string, buyer_id: string, due: int, lines: list<mixed>} $cart
     * @param list<array{role: string, amount: int, reference: string}> $tenders
     * @param list<mixed> $drafts
     * @return array<string, mixed>
     */
    public function salePanel(
        string $slug,
        string $session,
        array $cart,
        array $drafts,
        array $tenders,
        int $applied,
        string $held
    ): array {
        $giftNumber = trim($this->app->request()->query('gift'));
        $shopper = $this->register->shopper($cart['ticket_id']);

        return [
            'cart' => $this->payCart($session, $cart, $held),
            'drafts' => $drafts,
            'tenders' => $tenders,
            'deposits' => $this->register->deposits($cart['ticket_id']),
            'payments' => $this->register->payments($cart['ticket_id']),
            'shopperId' => $shopper['id'],
            'customerName' => $shopper['name'],
            'salesAvailable' => $cart['buyer_id'] === ''
                ? 0
                : max(0, $this->payouts->openPayout($cart['buyer_id'])['payable']),
            'remaining' => $cart['due'] - $applied,
            'giftNumber' => $giftNumber,
            'giftBalance' => $giftNumber === '' ? null : $this->gifts->balance($giftNumber),
            'completed' => $held === '' ? null : $this->poster->receipt($held),
            'lastSale' => $this->sales->last($slug),
            'change' => $this->change($slug, $held),
        ];
    }

    /**
     * @param array{ticket_id: string, lines: list<mixed>} $cart
     * @return array<string, mixed>
     */
    public function payCart(string $session, array $cart, string $held): array
    {
        $keys = [];
        if ($held === '' && $cart['lines'] !== []) {
            $keys = $this->register->snapKeys($session, $cart);
        }

        return $cart + ['snap_keys' => $keys];
    }

    /**
     * @return array<string, mixed>
     */
    public function chrome(TenantRecord $tenant, ?string $error): array
    {
        $request = $this->app->request();

        return [
            'title' => 'Sell',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'showSearch' => false,
            'error' => $error,
            'instantAdded' => $request->query('added') === '1',
            'instantAddedPage' => $request->query('instant_added') === '1',
        ];
    }

    private function change(string $slug, string $held): ?int
    {
        if ($held === '') {
            return null;
        }
        $tendered = $this->sales->tendered($slug);
        if ($tendered === '' || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $tendered) !== 1) {
            return null;
        }
        $cash = $this->poster->receipt($held)['cash'];
        $handed = Money::parseMinor($tendered);
        if ($cash < 1 || $handed < $cash) {
            return null;
        }

        return $handed - $cash;
    }
}
