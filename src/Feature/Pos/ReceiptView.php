<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;

final class ReceiptView
{
    public function __construct(
        private readonly MallApp $app,
        private readonly SalePoster $poster,
        private readonly JournalCash $cash,
        private readonly ReceiptChange $change,
        private readonly PartyStore $parties,
    ) {
    }

    public function show(string $slug, string $id, ?string $error): void
    {
        try {
            $receipt = $this->poster->receipt($id);
        } catch (DatabaseRefusal $refusal) {
            if ($refusal->getMessage() === 'that ticket is not a sale') {
                $this->app->notFound();
            }
            throw $refusal;
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/receipt', [
            'title' => 'Receipt',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'receipt' => $receipt,
            'change' => $this->change->due($this->cash->tendered($id), $receipt['cash']),
            'vendors' => $this->parties->vendors(),
            'error' => $error,
        ]);
    }
}
