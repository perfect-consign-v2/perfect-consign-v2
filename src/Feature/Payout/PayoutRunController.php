<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PayoutRunController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly PayoutRunStore $runs,
        private readonly PayoutSettler $settler,
        private readonly PayoutDisburser $disburser,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/past', ['GET'])]
    public function past(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Payout/history', [
            'title' => 'Past payouts',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'runs' => $this->runs->history(),
            'excluded' => $this->runs->excluded(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/close', ['POST'])]
    public function close(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $today = $this->mall->today();
        $window = $this->window($today);
        try {
            $runId = $this->runs->begin($window['from'], $window['to'], $window['rent'], $today);
            foreach ($this->runs->pack() as $row) {
                $settlementId = $this->settler->settle($runId, $row['vendor_id'], $today);
                $this->runs->remember(
                    $runId,
                    $row['vendor_id'],
                    $row['check'],
                    $row['owes'],
                    $settlementId === '' ? null : $settlementId
                );
            }
            $this->runs->markSettled($runId);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/payouts', 'Payout closed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/checks', ['POST'])]
    public function checks(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $today = $this->mall->today();
        $start = $this->app->request()->field('starting_check');
        $number = ctype_digit($start) ? (int) $start : 0;
        if ($number <= 0) {
            $this->render($slug, 'the first check number is required');

            return;
        }
        try {
            $runId = $this->app->request()->field('run_id');
            foreach ($this->runs->pack() as $row) {
                if ($row['check'] <= 0 || $row['owes'] > 0) {
                    continue;
                }
                $this->disburser->disburse($runId, $row['vendor_id'], $today, $row['check'], (string) $number);
                $number++;
            }
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $runId) === 1) {
                $this->runs->markPaid($runId);
            }
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/payouts', 'Checks written.');
    }

    /**
     * @return array{from: string, to: string, rent: string}
     */
    private function window(string $today): array
    {
        $rent = substr($today, 0, 7) . '-01';
        $to = (new \DateTimeImmutable($rent))->modify('-1 day')->format('Y-m-d');

        return [
            'from' => substr($to, 0, 7) . '-01',
            'to' => $to,
            'rent' => $rent,
        ];
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $pack = $this->runs->pack();
        $this->app->show('Feature/Payout/pay-vendors', [
            'title' => 'Pay vendors',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'pack' => $pack,
            'excluded' => $this->runs->excluded(),
            'runs' => $this->runs->history(),
            'error' => $error,
        ]);
    }
}
