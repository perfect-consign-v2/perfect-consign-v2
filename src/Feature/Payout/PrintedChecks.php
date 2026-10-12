<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PrintedChecks
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PayoutRunStore $runs,
        private readonly PayoutOnce $once,
    ) {
    }

    public function present(string $slug, string $runId): ?string
    {
        $run = $this->runs->run($runId);
        if (!$this->printable($run)) {
            return 'Those checks are not ready to print.';
        }
        if ($this->blankChecks($runId)) {
            return $this->ask($slug, $runId);
        }

        return $this->faces($slug, $runId, $run);
    }

    /**
     * @param array{tender: string, status: string, posting_date: string, rent_month: string} $run
     */
    private function printable(array $run): bool
    {
        return $run['status'] === 'paid' && $run['tender'] === 'check' && $this->day($run['posting_date']) !== '';
    }

    private function ask(string $slug, string $runId): ?string
    {
        $error = $this->app->request()->method === 'POST' ? $this->fillBlanks($runId) : null;
        if ($this->blankChecks($runId)) {
            $this->numberForm($slug, $runId, $error);

            return null;
        }

        return $this->faces($slug, $runId, $this->runs->run($runId));
    }

    private function fillBlanks(string $runId): ?string
    {
        $start = $this->app->request()->field('starting_check');
        $number = ctype_digit($start) ? (int) $start : 0;
        if ($number <= 0) {
            return 'Type the number on the first check.';
        }
        try {
            $this->once->numberChecks($runId, $number);
        } catch (DatabaseRefusal $refusal) {
            return $this->app->catchRefusal($refusal);
        }

        return null;
    }

    private function blankChecks(string $runId): bool
    {
        foreach ($this->runs->envelopes($runId) as $row) {
            if ($row['check'] > 0 && $row['check_number'] === '') {
                return true;
            }
        }

        return false;
    }

    private function numberForm(string $slug, string $runId, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Payout/check-number', [
            'title' => 'Print checks',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'runId' => $runId,
            'error' => $error,
        ]);
    }

    /**
     * @param array{tender: string, status: string, posting_date: string, rent_month: string} $run
     */
    private function faces(string $slug, string $runId, array $run): ?string
    {
        $checks = $this->once->savedChecks($runId);
        if ($checks === []) {
            return 'Nobody on this payout is getting a check.';
        }
        $tenant = $this->app->open($slug, true);
        $rent = $this->day($run['rent_month']);
        $monthName = $rent === '' ? 'Payout' : (new \DateTimeImmutable($rent))->format('F');
        $this->app->show('Feature/Payout/checks', [
            'title' => 'Checks',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'dated' => (new \DateTimeImmutable($run['posting_date']))->format('F j, Y'),
            'monthName' => $monthName,
            'checks' => $checks,
        ]);

        return null;
    }

    private function day(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return '';
        }

        return substr($value, 0, 10);
    }
}
