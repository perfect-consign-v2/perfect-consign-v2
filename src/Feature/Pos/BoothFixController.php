<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class BoothFixController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly MallSettings $mall,
        private readonly BoothCorrection $corrections,
        private readonly ReceiptView $receipts,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})/booth', ['POST'])]
    public function move(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->gate->session($slug);
        try {
            $this->corrections->move(
                $this->mall->today(),
                $id,
                $this->itemId(),
                $this->app->request()->field('vendor_id'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->receipts->show($slug, $id, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'The vendor payable moved.');
    }

    private function itemId(): string
    {
        $id = $this->app->request()->field('item_id');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new DatabaseRefusal('that item is not on the ticket');
        }

        return $id;
    }
}
