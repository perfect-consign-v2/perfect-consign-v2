<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\MallApp;

final class PayoutController
{
    public function __construct(private readonly MallApp $app)
    {
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
            $books = $this->app->books();
            $open = $books->openPayout($vendorId);
            $this->assertStatementAgrees($books->statement($vendorId), $open['payable'], $open['rent']);
            $lines = [];
            if ($open['payable'] > 0) {
                $lines[] = $this->line($books->roleAccount('vendor_payable'), $open['payable'], 0, 'vendor', $vendorId);
            }
            foreach ($open['booths'] as $booth) {
                $lines[] = $this->line(
                    $books->roleAccount('booth_rent_receivable'),
                    0,
                    $booth['open_minor'],
                    'booth',
                    $booth['booth_id']
                );
            }
            $cash = $open['payable'] - $open['rent'];
            if ($cash > 0) {
                $lines[] = $this->line($books->roleAccount('cash'), 0, $cash, null, null);
            } elseif ($cash < 0) {
                $lines[] = $this->line($books->roleAccount('cash'), -$cash, 0, null, null);
            }
            if ($lines === []) {
                throw new DatabaseRefusal('a payout clears a positive open payable or open rent receivable');
            }
            $journalId = $books->post(
                'payout:' . $vendorId . ':' . $month,
                $postingDate,
                'payout',
                $vendorId . ':' . $month,
                'Payout',
                $lines
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $vendorId, $this->app->catchRefusal($refusal));
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId);
    }

    /**
     * @param list<array<string, mixed>> $statement
     */
    private function assertStatementAgrees(array $statement, int $payable, int $rent): void
    {
        $statementPayable = 0;
        $statementRent = 0;
        foreach ($statement as $row) {
            $debit = (int) $row['debit_minor'];
            $credit = (int) $row['credit_minor'];
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
        $books = $this->app->books();
        $selected = is_string($vendorId) ? $vendorId : '';
        $open = null;
        $statement = [];
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $selected) === 1) {
            $open = $books->openPayout($selected);
            $statement = $books->statement($selected);
        }
        $this->app->show('payout', [
            'title' => 'Payout',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $books->vendors(),
            'vendorId' => $selected,
            'open' => $open,
            'statement' => $statement,
            'error' => $error,
        ]);
    }

    /**
     * @return array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}
     */
    private function line(string $accountId, int $debit, int $credit, ?string $type, ?string $ref): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'subledger_type' => $type,
            'subledger_ref' => $ref,
        ];
    }
}
