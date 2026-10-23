<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Check;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;

final class NsfController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly AuditLog $audit,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/checks/nsf', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Check/nsf', [
            'title' => 'Bounced check',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/checks/nsf', ['POST'])]
    public function post(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $reference = $request->field('source_reference');
        try {
            if ($reference === '') {
                throw new DatabaseRefusal('a source reference is required');
            }
            $amount = Money::parseMinor($request->field('amount'));
            $journalId = $this->ledger->post(new JournalDraft(
                postingKey: 'check-return:' . $reference,
                postingDate: $request->field('posting_date'),
                source: new JournalSource('check_return', $reference, 'Bounced check'),
                lines: [
                    [
                        'account_id' => $this->ledger->roleAccount('returned_checks'),
                        'debit' => $amount,
                        'credit' => 0,
                        'subledger_type' => null,
                        'subledger_ref' => null,
                    ],
                    [
                        'account_id' => $this->ledger->roleAccount('checks'),
                        'debit' => 0,
                        'credit' => $amount,
                        'subledger_type' => null,
                        'subledger_ref' => null,
                    ],
                ],
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->fail($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->fail($slug, $error->getMessage());

            return;
        }
        $actor = $this->app->request()->field('actor');
        $this->audit->record($actor !== '' ? $actor : 'owner', 'owner', 'check.return', 'journal', $journalId);
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Bounced check posted.');
    }

    private function fail(string $slug, string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Check/nsf', [
            'title' => 'Bounced check',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => $error,
        ]);
    }
}
