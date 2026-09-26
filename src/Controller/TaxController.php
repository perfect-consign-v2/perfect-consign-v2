<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\MallApp;

final class TaxController
{
    public function __construct(private readonly MallApp $app)
    {
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
            if ($credit !== 'cash' && $credit !== 'bank') {
                throw new DatabaseRefusal('a tax payment credits cash or bank');
            }
            $books = $this->app->books();
            $journalId = $books->post(
                'tax-payment:' . $reference,
                $this->app->request()->field('posting_date'),
                'tax_payment',
                $reference,
                'Sales tax payment',
                [
                    [
                        'account_id' => $books->roleAccount('sales_tax_payable'),
                        'debit' => $amount,
                        'credit' => 0,
                        'subledger_type' => null,
                        'subledger_ref' => null,
                    ],
                    [
                        'account_id' => $books->roleAccount($credit),
                        'debit' => 0,
                        'credit' => $amount,
                        'subledger_type' => null,
                        'subledger_ref' => null,
                    ],
                ]
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('tax', [
            'title' => 'Sales tax',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'openTax' => $this->app->books()->openTax(),
            'error' => $error,
        ]);
    }
}
