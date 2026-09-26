<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;

final class JournalController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/journals', ['GET'])]
    public function index(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Book/journals', [
            'title' => 'Journals',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'journals' => $this->ledger->journals(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/journals/([0-9a-fA-F-]{36})', ['GET'])]
    public function show(string $slug, string $id): void
    {
        $tenant = $this->app->open($slug, true);
        $journal = $this->ledger->journal($id);
        if ($journal === null) {
            $this->app->notFound();
        }
        $this->app->show('Feature/Book/journal', [
            'title' => 'Journal',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'journal' => $journal,
            'lines' => $this->ledger->lines($id),
            'canVoid' => $this->canVoid($journal, $this->ledger->reversed($id)),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/journals/([0-9a-fA-F-]{36})/void', ['POST'])]
    public function void(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $journal = $this->ledger->journal($id);
        if ($journal === null || !$this->canVoid($journal, false)) {
            $this->app->notFound();
        }
        $lines = [];
        foreach ($this->ledger->lines($id) as $line) {
            $subledgerType = $line['subledger_type'];
            $subledgerRef = $line['subledger_ref'];
            $lines[] = [
                'account_id' => (string) $line['account_id'],
                'debit' => (int) $line['credit_minor'],
                'credit' => (int) $line['debit_minor'],
                'subledger_type' => is_string($subledgerType) && $subledgerType !== '' ? $subledgerType : null,
                'subledger_ref' => is_string($subledgerRef) && $subledgerRef !== '' ? $subledgerRef : null,
            ];
        }
        $postingDate = $this->app->request()->field('posting_date');
        if ($postingDate === '') {
            $postingDate = (string) $journal['posting_date'];
        }
        try {
            $voidId = $this->ledger->post(
                'reversal:' . $id,
                $postingDate,
                (string) $journal['source_type'],
                (string) $journal['source_reference'] . ':void',
                'Void of ' . $journal['posting_key'],
                $lines,
                true,
                $id
            );
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('Feature/Book/journal', [
                'title' => 'Journal',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'journal' => $journal,
                'lines' => $this->ledger->lines($id),
                'canVoid' => true,
                'error' => $this->app->catchRefusal($refusal),
            ]);
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $voidId);
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function canVoid(array $journal, bool $alreadyReversed): bool
    {
        if ($alreadyReversed || $journal['source_type'] === 'period_close') {
            return false;
        }
        $reversal = $journal['is_reversal'];

        return $reversal !== true && $reversal !== 't' && $reversal !== '1' && $reversal !== 1;
    }
}
