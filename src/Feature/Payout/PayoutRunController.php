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
        private readonly PrintedChecks $printed,
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
        $window = $this->window->window($today, $this->mall->getCycleDay());
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
        $window = $this->window->window($today, $this->mall->getCycleDay());
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
        $runId = $this->app->request()->query('run');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $runId) !== 1) {
            $this->render($slug, 'That payout is missing.');

            return;
        }
        $error = $this->printed->present($slug, $runId);
        if ($error !== null) {
            $this->render($slug, $error);
        }
    }

    private function drawer(string $slug, string $tender): ?string
    {
        if ($tender !== 'drawer_cash') {
            return '';
        }
        $sessionId = $this->register->chosen($slug, true)['id'];

        return $sessionId === '' ? null : $sessionId;
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $window = $this->window->window($today, $this->mall->getCycleDay());
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
            'pack' => $this->runs->addBooths($pack, $today),
            'savedRun' => $finished === null ? '' : $finished['id'],
            'savedTender' => $finished === null ? '' : $finished['tender'],
            'savedStatus' => $finished === null ? '' : $finished['status'],
            'excluded' => $this->runs->excluded(),
            'error' => $error,
        ]);
    }
}
