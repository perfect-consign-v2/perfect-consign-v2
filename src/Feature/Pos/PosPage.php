<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;

final class PosPage
{
    public function __construct(
        private readonly MallApp $app,
        private readonly TicketStore $tickets,
        private readonly RegisterGate $gate,
    ) {
    }

    public function cart(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/pos', [
            'title' => 'POS',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'cart' => $this->tickets->cart($this->gate->session($slug)),
            'error' => $error,
        ]);
    }
}
