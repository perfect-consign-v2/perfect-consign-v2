<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\Money;

final class PosPage
{
    public function __construct(
        private readonly MallApp $app,
        private readonly TicketStore $tickets,
        private readonly TicketTenders $held,
        private readonly GiftIssue $gifts,
        private readonly RegisterGate $gate,
        private readonly SaleHold $sales,
        private readonly SalePoster $poster,
        private readonly LayawayBook $layaways,
    ) {
    }

    public function cart(string $slug, ?string $error, bool $reopenInstant = false): void
    {
        $tenant = $this->app->open($slug, true);
        $cart = $this->tickets->cart($this->gate->session($slug));
        $tenders = $this->held->list($cart['ticket_id']);
        $applied = $this->layaways->balance($cart['ticket_id']);
        foreach ($tenders as $tender) {
            $applied += $tender['amount'];
        }
        $giftNumber = trim($this->app->request()->query('gift'));
        $held = $this->sales->id($slug);
        $this->app->show('Feature/Pos/pos', [
            'title' => 'POS',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'showSearch' => false,
            'cart' => $cart,
            'tenders' => $tenders,
            'deposits' => $this->layaways->balance($cart['ticket_id']),
            'remaining' => $cart['due'] - $applied,
            'giftNumber' => $giftNumber,
            'giftBalance' => $giftNumber === '' ? null : $this->gifts->balance($giftNumber),
            'completed' => $held === '' ? null : $this->poster->receipt($held),
            'change' => $this->change($slug, $held),
            'error' => $error,
            'openInstant' => $reopenInstant || $this->app->request()->query('instant') === '1',
        ]);
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

    public function held(string $slug, string $status): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/held', [
            'title' => $status === 'layaway' ? 'Layaways' : 'Suspended sales',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'showSearch' => false,
            'cart' => $this->tickets->cart($this->gate->session($slug)),
            'heldStatus' => $status,
        ]);
    }
}
