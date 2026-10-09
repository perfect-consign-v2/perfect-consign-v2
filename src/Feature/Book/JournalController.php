<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class JournalController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly RegisterStore $registers,
        private readonly ActiveRegister $drawers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/journals', ['GET'])]
    public function index(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Book/journals', [
            'title' => 'Posted entries',
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
            'title' => 'Posted entry',
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
        try {
            $source = RowValue::string($journal['source_type'] ?? null);
            $sessionId = $this->registers->drawerSource($source)
                ? $this->registers->openSession($this->drawers->posted($slug, $this->app->request()->field('register_session_id'), true))
                : null;
            $voidId = $this->ledger->post(new JournalDraft(
                postingKey: 'reversal:' . $id,
                postingDate: $this->postingDate($journal),
                source: new JournalSource(
                    $source,
                    RowValue::string($journal['source_reference'] ?? null) . ':void',
                    'Void of ' . RowValue::string($journal['posting_key'] ?? null),
                ),
                lines: $this->swappedLines($id),
                reversesJournalId: $id,
                sessionId: $sessionId,
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->showRefusal($tenant, $journal, $id, $refusal);
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $voidId, 'Journal voided.');
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function postingDate(array $journal): string
    {
        $posted = $this->app->request()->field('posting_date');
        if ($posted !== '') {
            return $posted;
        }

        return RowValue::string($journal['posting_date'] ?? null);
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function swappedLines(string $id): array
    {
        $lines = [];
        foreach ($this->ledger->lines($id) as $line) {
            $lines[] = [
                'account_id' => RowValue::string($line['account_id'] ?? null),
                'debit' => RowValue::integer($line['credit_minor'] ?? null),
                'credit' => RowValue::integer($line['debit_minor'] ?? null),
                'subledger_type' => RowValue::optionalString($line['subledger_type'] ?? null),
                'subledger_ref' => RowValue::optionalString($line['subledger_ref'] ?? null),
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function showRefusal(TenantRecord $tenant, array $journal, string $id, DatabaseRefusal $refusal): void
    {
        $this->app->show('Feature/Book/journal', [
            'title' => 'Posted entry',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'journal' => $journal,
            'lines' => $this->ledger->lines($id),
            'canVoid' => true,
            'error' => $this->app->catchRefusal($refusal),
        ]);
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
