<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

/**
 * "Hold it for Mary Smith": a piece found after its vendor left is set aside for them.
 */
final class ItemHoldController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly DepartedPiece $pieces,
        private readonly MallSettings $mall,
        private readonly Actor $actor,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})/hold', ['POST'])]
    public function hold(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $piece = $this->pieces->of($id);
        if ($piece === null) {
            $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', null, 'Only a piece from a vendor who has left is held for them.');
        }
        try {
            $this->pieces->hold($id, $this->mall->today(), CountWho::of($this->actor)->name);
        } catch (DatabaseRefusal $refusal) {
            $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', null, $this->app->catchRefusal($refusal));
        }
        $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', 'Held for ' . $piece['vendor_name'] . '.');
    }
}
