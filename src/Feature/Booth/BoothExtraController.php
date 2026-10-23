<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Booth;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;

final class BoothExtraController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-extras', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Booth/booth-extras', [
            'title' => 'Booth extras',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-extras', ['POST'])]
    public function charge(string $slug): void
    {
        $this->post($slug, 'booth_charge', 'booth-charge:', 'Booth charge', true);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-deposit', ['POST'])]
    public function deposit(string $slug): void
    {
        $this->post($slug, 'booth_deposit', 'booth-deposit:', 'Booth deposit', false);
    }

    private function post(string $slug, string $source, string $key, string $description, bool $charge): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $reference = $request->field('source_reference');
        $booth = $request->field('booth_id');
        try {
            if ($reference === '' || $booth === '') {
                throw new DatabaseRefusal('a booth and a reference are required');
            }
            $amount = Money::parseMinor($request->field('amount'));
            $debit = $charge ? 'booth_rent_receivable' : 'cash';
            $credit = $charge ? 'booth_other_revenue' : 'booth_deposits';
            $this->ledger->post(new JournalDraft(
                postingKey: $key . $reference,
                postingDate: $request->field('posting_date'),
                source: new JournalSource($source, $reference, $description),
                lines: [
                    [
                        'account_id' => $this->ledger->roleAccount($debit),
                        'debit' => $amount,
                        'credit' => 0,
                        'subledger_type' => $charge ? 'booth' : null,
                        'subledger_ref' => $charge ? $booth : null,
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
            $tenant = $this->app->open($slug, true);
            $this->app->show('Feature/Booth/booth-extras', [
                'title' => 'Booth extras',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => $this->app->catchRefusal($refusal),
            ]);

            return;
        } catch (\InvalidArgumentException $error) {
            $tenant = $this->app->open($slug, true);
            $this->app->show('Feature/Booth/booth-extras', [
                'title' => 'Booth extras',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => $error->getMessage(),
            ]);

            return;
        }
        $this->app->redirect('/' . $slug . '/booth-extras', $description . ' posted.');
    }
}
