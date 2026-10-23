<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Tax;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;

final class TaxController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly TaxStore $tax,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/tax', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/tax', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $reference = $this->app->request()->field('source_reference');
        try {
            if ($reference === '') {
                throw new DatabaseRefusal('a source reference is required');
            }
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $credit = $this->app->request()->field('credit');
            if ($credit !== 'bank') {
                throw new DatabaseRefusal('a tax payment credits the bank');
            }
            $journalId = $this->ledger->post(new JournalDraft(
                postingKey: 'tax-payment:' . $reference,
                postingDate: $this->app->request()->field('posting_date'),
                source: new JournalSource('tax_payment', $reference, 'Sales tax payment'),
                lines: [
                    [
                        'account_id' => $this->ledger->roleAccount('sales_tax_payable'),
                        'debit' => $amount,
                        'credit' => 0,
                        'subledger_type' => null,
                        'subledger_ref' => null,
                    ],
                    [
                        'account_id' => $this->ledger->roleAccount($credit),
                        'debit' => 0,
                        'credit' => $amount,
                        'subledger_type' => null,
                        'subledger_ref' => null,
                    ],
                ],
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Tax payment posted.');
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Tax/tax', [
            'title' => 'Sales tax',
            'amount' => $this->app->request()->query('amount'),
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'openTax' => $this->tax->openTax(),
            'error' => $error,
        ]);
    }
}
