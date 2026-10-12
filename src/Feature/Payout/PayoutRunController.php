<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PayoutRunController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly PayoutRunStore $runs,
        private readonly PayoutWindow $window,
        private readonly PayoutOnce $once,
        private readonly PayoutReverser $reverser,
        private readonly ActiveRegister $register,
        private readonly MallClock $clock,
        private readonly RentShortfall $shortfall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/report', ['GET'])]
    public function report(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $window = $this->window->window($today);
        $requested = $this->app->request()->query('run');
        $rows = preg_match('/^[0-9a-fA-F-]{36}$/', $requested) === 1
            ? $this->runs->envelopes($requested)
            : $this->runs->envelopes('', $window['from'], $window['to'], $window['rent'], $today);
        $this->app->show('Feature/Payout/envelope', [
            'title' => 'Each vendor\'s sales',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'rows' => $rows,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/past', ['GET'])]
    public function past(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Payout/history', [
            'title' => 'Past payouts',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'runs' => $this->shown($this->runs->history()),
            'excluded' => $this->runs->excluded(),
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/undo', ['POST'])]
    public function undo(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $runId = $this->app->request()->field('run_id');
        $reason = trim($this->app->request()->field('reason'));
        $actor = $this->register->ownerName();
        if ($actor === '' || $reason === '') {
            $this->pastWith($slug, 'a payout reversal needs the operator and a reason');

            return;
        }
        try {
            $this->reverser->undo($runId, $this->mall->today(), $actor, $reason);
        } catch (DatabaseRefusal $refusal) {
            $this->pastWith($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/payouts/past', 'Payout undone.');
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return list<array<string, mixed>>
     */
    private function shown(array $runs): array
    {
        $shown = [];
        foreach ($runs as $run) {
            $at = $run['reversed_at'] ?? null;
            $run['undone_at'] = is_string($at) && $at !== '' ? $this->clock->localTime($at) : '';
            $shown[] = $run;
        }

        return $shown;
    }

    private function pastWith(string $slug, string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Payout/history', [
            'title' => 'Past payouts',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'runs' => $this->shown($this->runs->history()),
            'excluded' => $this->runs->excluded(),
            'error' => $error,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/close', ['POST'])]
    public function close(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $today = $this->mall->today();
        $window = $this->window->window($today);
        if ($this->once->finishedRun($window['from'], $window['to'], $window['rent']) !== null) {
            $this->render($slug, 'This month is already paid.');

            return;
        }
        $tender = $this->app->request()->field('tender');
        if ($tender !== 'check' && $tender !== 'drawer_cash' && $tender !== 'safe_cash') {
            $this->render($slug, 'Choose how to pay.');

            return;
        }
        $sessionId = $this->drawer($slug, $tender);
        if ($sessionId === null) {
            $this->render($slug, 'Open the drawer before paying cash from it.');

            return;
        }
        try {
            $session = $tender === 'drawer_cash' ? $sessionId : null;
            $this->once->pay($window['from'], $window['to'], $window['rent'], $today, $tender, $session);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $monthName = (new \DateTimeImmutable($window['rent']))->format('F');
        $this->app->redirect('/' . $slug . '/payouts', $monthName . ' is paid.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/checks', ['GET', 'POST'])]
    public function checks(string $slug): void
    {
        if ($this->app->request()->method === 'POST') {
            $this->app->checkCsrf();
        }
        $tenant = $this->app->open($slug, true);
        $runId = $this->app->request()->query('run');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $runId) !== 1) {
            $this->render($slug, 'That payout is missing.');

            return;
        }
        $run = $this->runs->run($runId);
        if (!$this->printable($run)) {
            $this->render($slug, 'Those checks are not ready to print.');

            return;
        }
        $error = null;
        if ($this->app->request()->method === 'POST' && $this->blankChecks($runId)) {
            $error = $this->fillBlanks($runId);
        }
        if ($this->blankChecks($runId)) {
            $this->app->show('Feature/Payout/check-number', [
                'title' => 'Print checks',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'runId' => $runId,
                'error' => $error,
            ]);

            return;
        }
        $checks = $this->once->savedChecks($runId);
        if ($checks === []) {
            $this->render($slug, 'Nobody on this payout is getting a check.');

            return;
        }
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
    }

    /**
     * @param array{tender: string, status: string, posting_date: string, rent_month: string} $run
     */
    private function printable(array $run): bool
    {
        return $run['status'] === 'paid' && $run['tender'] === 'check' && $this->day($run['posting_date']) !== '';
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

    private function drawer(string $slug, string $tender): ?string
    {
        if ($tender !== 'drawer_cash') {
            return '';
        }
        $sessionId = $this->register->chosen($slug, true)['id'];

        return $sessionId === '' ? null : $sessionId;
    }

    private function day(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return '';
        }

        return substr($value, 0, 10);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $window = $this->window->window($today);
        $monthName = (new \DateTimeImmutable($window['rent']))->format('F');
        $finished = $this->once->finishedRun($window['from'], $window['to'], $window['rent']);
        $pack = $finished === null
            ? $this->once->openPack($window['from'], $window['to'], $window['rent'], $today)
            : $this->once->savedPack($finished['id']);
        $this->app->show('Feature/Payout/pay-vendors', [
            'title' => $monthName . ' payout',
            'monthName' => $monthName,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'pack' => $this->shortfall->attach($this->runs->addBooths($pack, $today), $window['rent']),
            'savedRun' => $finished === null ? '' : $finished['id'],
            'savedTender' => $finished === null ? '' : $finished['tender'],
            'savedStatus' => $finished === null ? '' : $finished['status'],
            'excluded' => $this->runs->excluded(),
            'error' => $error,
        ]);
    }
}
