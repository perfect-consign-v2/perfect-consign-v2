<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly PayoutStore $payouts,
        private readonly PartyStore $parties,
        private readonly RegisterStore $registers,
        private readonly ActiveRegister $drawers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payout', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, $this->app->request()->query['vendor'] ?? '', null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payout', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->app->request()->field('vendor_id');
        $month = $this->app->request()->field('month');
        $postingDate = $this->app->request()->field('posting_date');
        try {
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                throw new DatabaseRefusal('a payout month is YYYY-MM');
            }
            $open = $this->payouts->openPayout($vendorId);
            $this->assertStatementAgrees($this->payouts->statement($vendorId), $open['payable'], $open['rent']);
            $lines = [];
            if ($open['payable'] > 0) {
                $lines[] = PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $open['payable'], 0, 'vendor', $vendorId);
            }
            foreach ($open['booths'] as $booth) {
                $lines[] = PostedLines::entry(
                    $this->ledger->roleAccount('booth_rent_receivable'),
                    0,
                    $booth['open_minor'],
                    'booth',
                    $booth['booth_id']
                );
            }
            $cash = $open['payable'] - $open['rent'];
            if ($cash > 0) {
                $lines[] = PostedLines::entry($this->ledger->roleAccount('cash'), 0, $cash, null, null);
            } elseif ($cash < 0) {
                $lines[] = PostedLines::entry($this->ledger->roleAccount('cash'), -$cash, 0, null, null);
            }
            if ($lines === []) {
                throw new DatabaseRefusal('a payout clears a positive open payable or open rent receivable');
            }
            $journalId = $this->ledger->post(new JournalDraft(
                postingKey: 'payout:' . $vendorId . ':' . $month,
                postingDate: $postingDate,
                source: new JournalSource('payout', $vendorId . ':' . $month, 'Payout'),
                lines: $lines,
                sessionId: $this->registers->openSession($this->drawers->posted($slug, $this->app->request()->field('register_session_id'), true)),
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $vendorId, $this->app->catchRefusal($refusal));
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Payout posted.');
    }

    /**
     * @param list<array<string, mixed>> $statement
     */
    private function assertStatementAgrees(array $statement, int $payable, int $rent): void
    {
        $statementPayable = 0;
        $statementRent = 0;
        foreach ($statement as $row) {
            $debit = RowValue::integer($row['debit_minor'] ?? null);
            $credit = RowValue::integer($row['credit_minor'] ?? null);
            if ($row['role'] === 'vendor_payable') {
                $statementPayable += $credit - $debit;
            }
            if ($row['role'] === 'booth_rent_receivable') {
                $statementRent += $debit - $credit;
            }
        }
        if ($statementPayable !== $payable || $statementRent !== $rent) {
            throw new DatabaseRefusal('the statement and the open balances disagree');
        }
    }

    private function render(string $slug, mixed $vendorId, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $selected = is_string($vendorId) ? $vendorId : '';
        $open = null;
        $statement = [];
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $selected) === 1) {
            $open = $this->payouts->openPayout($selected);
            $statement = $this->payouts->statement($selected);
        }
        $this->app->show('Feature/Payout/payout', [
            'title' => 'Payout',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $this->parties->vendors(),
            'vendorId' => $selected,
            'open' => $open,
            'statement' => $statement,
            'error' => $error,
        ]);
    }
}
