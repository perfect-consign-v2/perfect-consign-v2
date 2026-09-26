<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Close;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;

final class CloseController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly CloseStore $close,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/close', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/close', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $periodId = $this->app->request()->field('period_id');
        $endsOn = $this->app->request()->field('ends_on');
        try {
            $movement = $this->close->periodMovement($periodId);
            if ($movement === []) {
                $this->close->closeQuiet($periodId);
                $this->app->redirect('/' . $slug . '/close');
            }
            $lines = [];
            $net = 0;
            foreach ($movement as $row) {
                $amount = $row['net_minor'];
                $net += $amount;
                $lines[] = [
                    'account_id' => $row['account_id'],
                    'debit' => $amount > 0 ? $amount : 0,
                    'credit' => $amount < 0 ? -$amount : 0,
                    'subledger_type' => null,
                    'subledger_ref' => null,
                ];
            }
            if ($net !== 0) {
                $lines[] = [
                    'account_id' => $this->ledger->roleAccount('retained_earnings'),
                    'debit' => $net < 0 ? -$net : 0,
                    'credit' => $net > 0 ? $net : 0,
                    'subledger_type' => null,
                    'subledger_ref' => null,
                ];
            }
            $journalId = $this->ledger->post(
                'period-close:' . $periodId,
                $endsOn,
                'period_close',
                $periodId,
                'Period close',
                $lines
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Close/close', [
            'title' => 'Year-end close',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'periods' => $this->ledger->periods(),
            'error' => $error,
        ]);
    }
}
