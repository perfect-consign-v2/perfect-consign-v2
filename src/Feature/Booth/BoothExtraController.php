<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Booth;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Database\Statements;

final class BoothExtraController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly ActiveRegister $register,
        private readonly Actor $actor,
        private readonly AuditLog $audit,
        private readonly Statements $statements,
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
        $this->post($slug, 'booth_charge', 'booth-charge:', 'Booth charge', true, false);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-deposit', ['POST'])]
    public function deposit(string $slug): void
    {
        $this->post($slug, 'booth_deposit', 'booth-deposit:', 'Booth deposit', false, false);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-deposit/release', ['POST'])]
    public function release(string $slug): void
    {
        $this->post($slug, 'booth_deposit', 'booth-deposit-release:', 'Booth deposit returned', false, true);
    }

    private function post(string $slug, string $source, string $key, string $description, bool $charge, bool $release): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $this->record($slug, $source, $key, $description, $charge, $release);
        } catch (DatabaseRefusal $refusal) {
            $this->showFailure($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->showFailure($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/booth-extras', $description . ' posted.');
    }

    private function record(string $slug, string $source, string $key, string $description, bool $charge, bool $release): void
    {
        $request = $this->app->request();
        $reference = $request->field('source_reference');
        $booth = $request->field('booth_id');
        if ($reference === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $booth) !== 1) {
            throw new DatabaseRefusal('a booth and a reference are required');
        }
        if ($this->statements->bound('SELECT id::text AS id FROM booths WHERE id = ?::uuid', [$booth]) === []) {
            throw new DatabaseRefusal('that booth does not exist');
        }
        $amount = Money::parseMinor($request->field('amount'));
        [$actor, $role] = $this->signer();
        [$debit, $credit] = $this->sides($charge, $release);
        $debitBooth = $debit === 'booth_deposit_held' || $debit === 'booth_rent_receivable' ? $booth : null;
        $creditBooth = $credit === 'booth_deposit_held' || $credit === 'booth_rent_receivable' ? $booth : null;
        $this->ledger->post(new JournalDraft(
            postingKey: $key . $reference,
            postingDate: $request->field('posting_date'),
            source: new JournalSource($source, $reference, $description),
            sessionId: $this->drawer($slug, $charge),
            beforeCommit: function (string $journalId) use ($actor, $role, $source): void {
                $this->audit->record($actor, $role, $source, 'journal', $journalId);
            },
            lines: [
                $this->line($debit, $amount, 0, $debitBooth),
                $this->line($credit, 0, $amount, $creditBooth),
            ],
        ));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function signer(): array
    {
        $actor = $this->actor->name();
        $role = $this->actor->role();
        if ($actor === '' || $role === '') {
            throw new DatabaseRefusal('the signed-in person is missing');
        }

        return [$actor, $role];
    }

    private function drawer(string $slug, bool $charge): ?string
    {
        if ($charge) {
            return null;
        }
        $session = $this->register->current($slug, true);
        if ($session === '') {
            throw new DatabaseRefusal('open a register before taking a deposit');
        }

        return $session;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sides(bool $charge, bool $release): array
    {
        if ($release) {
            return ['booth_deposit_held', 'cash'];
        }
        if ($charge) {
            return ['booth_rent_receivable', 'booth_other_revenue'];
        }

        return ['cash', 'booth_deposit_held'];
    }

    /**
     * @return array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}
     */
    private function line(string $role, int $debit, int $credit, ?string $booth): array
    {
        return [
            'account_id' => $this->ledger->roleAccount($role),
            'debit' => $debit,
            'credit' => $credit,
            'subledger_type' => $booth === null ? null : 'booth',
            'subledger_ref' => $booth,
        ];
    }

    private function showFailure(string $slug, string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Booth/booth-extras', [
            'title' => 'Booth extras',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => $error,
        ]);
    }
}
