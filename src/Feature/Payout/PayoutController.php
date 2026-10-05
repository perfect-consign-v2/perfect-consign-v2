<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PayoutController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PayoutStore $payouts,
        private readonly PartyStore $parties,
        private readonly PayoutSingle $single,
        private readonly MallSettings $mall,
        private readonly ActiveRegister $register,
        private readonly MallClock $clock,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payout', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, $this->app->request()->query['vendor'] ?? '', null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payout/undo', ['POST'])]
    public function undo(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->app->request()->field('vendor_id');
        $runId = $this->app->request()->field('run_id');
        $reason = trim($this->app->request()->field('reason'));
        try {
            $this->single->undo($runId, $this->mall->today(), $this->register->ownerName(), $reason);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $vendorId, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/payout?vendor=' . rawurlencode($vendorId), 'Payout undone.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payout', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->app->request()->field('vendor_id');
        $postingDate = $this->mall->today();
        try {
            $journalId = $this->single->pay($vendorId, $postingDate);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $vendorId, $this->app->catchRefusal($refusal));
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Payout posted.');
    }

    private function render(string $slug, mixed $vendorId, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $vendors = $this->parties->vendors();
        $selected = is_string($vendorId) ? $vendorId : '';
        if ($selected === '' && count($vendors) === 1) {
            $only = $vendors[0]['id'] ?? '';
            $selected = is_string($only) ? $only : '';
        }
        $open = null;
        $undone = null;
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $selected) === 1) {
            $latest = $this->single->lastUndone($selected);
            if ($latest !== null) {
                $undone = [
                    'by' => $latest['by'],
                    'reason' => $latest['reason'],
                    'at' => $this->clock->localTime($latest['at']),
                ];
            }
            $quoted = $this->payouts->quote($selected, $this->mall->today());
            if ($quoted !== null) {
                $open = [
                    'payable' => $quoted['payable'],
                    'rent' => $quoted['rent'],
                    'check' => $quoted['check'],
                    'owes' => $quoted['owes'],
                    'settled' => $quoted['settled'],
                    'rent_from_sales' => $quoted['rent_from_sales'],
                ];
            }
        }
        $this->app->show('Feature/Payout/payout', [
            'title' => 'Pay this vendor',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $vendors,
            'vendorId' => $selected,
            'open' => $open,
            'runId' => preg_match('/^[0-9a-fA-F-]{36}$/', $selected) === 1 ? $this->single->openSingle($selected) : '',
            'undone' => $undone,
            'error' => $error,
        ]);
    }
}
