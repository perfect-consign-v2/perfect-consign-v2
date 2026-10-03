<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly PayoutStore $payouts,
        private readonly PartyStore $parties,
        private readonly PayoutSettler $settler,
        private readonly PayoutDisburser $disburser,
        private readonly MallSettings $mall,
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
        $postingDate = $this->mall->today();
        try {
            $this->settler->chargeMissing($vendorId, $postingDate);
            $open = $this->payouts->openPayout($vendorId);
            $this->assertStatementAgrees($this->payouts->statement($vendorId), $open['payable'], $open['rent']);
            $applied = min($open['payable'], $open['rent']);
            $check = $open['payable'] - $open['rent'];
            if ($applied <= 0 && $check <= 0) {
                throw new DatabaseRefusal('a payout clears a positive open payable or open rent receivable');
            }
            $runId = $this->ledger->newId();
            $journalId = $this->settler->settle($runId, $vendorId, $postingDate);
            $paid = $this->disburser->disburse($runId, $vendorId, $postingDate, max(0, $check), null);
            if ($paid !== '') {
                $journalId = $paid;
            }
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
        $vendors = $this->parties->vendors();
        $selected = is_string($vendorId) ? $vendorId : '';
        if ($selected === '' && count($vendors) === 1) {
            $only = $vendors[0]['id'] ?? '';
            $selected = is_string($only) ? $only : '';
        }
        $open = null;
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $selected) === 1) {
            $loaded = $this->payouts->openPayout($selected);
            foreach ($this->payouts->missingRent($selected, $this->mall->today()) as $charge) {
                $loaded['rent'] += $charge['amount'];
            }
            $open = [
                'payable' => $loaded['payable'],
                'rent' => $loaded['rent'],
                'check' => max(0, $loaded['payable'] - $loaded['rent']),
                'owes' => max(0, $loaded['rent'] - $loaded['payable']),
            ];
        }
        $this->app->show('Feature/Payout/payout', [
            'title' => 'Pay this vendor',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $vendors,
            'vendorId' => $selected,
            'open' => $open,
            'error' => $error,
        ]);
    }
}
