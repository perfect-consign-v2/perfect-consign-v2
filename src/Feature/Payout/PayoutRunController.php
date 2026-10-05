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
        private readonly PayoutSettler $settler,
        private readonly PayoutDisburser $disburser,
        private readonly PayoutReverser $reverser,
        private readonly ActiveRegister $register,
        private readonly MallClock $clock,
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
        $runId = $this->app->request()->query('run');
        $this->app->show('Feature/Payout/envelope', [
            'title' => 'Payout report',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'rows' => preg_match('/^[0-9a-fA-F-]{36}$/', $runId) === 1 ? $this->runs->envelopes($runId) : [],
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
        $window = $this->runs->window($today);
        try {
            $runId = $this->runs->ensureDraft($window['from'], $window['to'], $window['rent'], $today);
            $this->runs->review($runId);
            $this->settler->close($runId, $today);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/payouts', 'Payout closed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/payouts/checks', ['POST'])]
    public function checks(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $today = $this->mall->today();
        $start = $this->app->request()->field('starting_check');
        $number = ctype_digit($start) ? (int) $start : 0;
        if ($number <= 0) {
            $this->render($slug, 'the first check number is required');

            return;
        }
        try {
            $runId = $this->app->request()->field('run_id');
            $this->disburser->pay($runId, $today, $number);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/payouts', 'Checks written.');
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $window = $this->runs->window($today);
        $monthName = (new \DateTimeImmutable($window['rent']))->format('F');
        $draft = $this->runs->openDraft($window['from'], $window['to'], $window['rent']);
        $pack = $draft === null
            ? $this->runs->preview($window['from'], $window['to'], $window['rent'], $today)
            : $this->runs->pack($draft);
        usort(
            $pack,
            static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code'])
        );
        $this->app->show('Feature/Payout/pay-vendors', [
            'title' => $monthName . ' payout',
            'monthName' => $monthName,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'pack' => $pack,
            'excluded' => $this->runs->excluded(),
            'runs' => $this->runs->history(),
            'error' => $error,
        ]);
    }
}
