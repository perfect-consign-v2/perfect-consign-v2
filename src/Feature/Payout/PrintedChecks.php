<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Bootstrap\MallApp;

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
        $this->app->open($slug, true);
        $run = $this->runs->run($runId);
        if ($run['status'] === '') {
            return 'That payout is missing.';
        }
        if ($this->runs->checksVoided($runId)) {
            return 'That check was voided.';
        }
        if (!$this->printable($run)) {
            return 'Those checks are not ready to print.';
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
            'runId' => $runId,
            'dated' => (new \DateTimeImmutable($run['posting_date']))->format('m-d-Y'),
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
