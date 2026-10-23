<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class RegisterShiftController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly RegisterStore $registers,
        private readonly RegisterSession $sessions,
        private readonly ActiveRegister $drawers,
        private readonly RegisterHub $hub,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/open', ['POST'])]
    public function open(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->registers->find($id) === null) {
            $this->app->notFound();
        }
        $draft = [
            'cashier' => $this->app->request()->field('cashier'),
            'float' => $this->app->request()->field('opening_float'),
        ];
        try {
            $counts = DenominationCount::fromRequest($this->app->request());
            $draft['counts'] = $counts;
            $sessionId = $this->sessions->open(
                $id,
                $draft['cashier'],
                $counts,
                DenominationCount::money($draft['float'], true)
            );
            $opened = $this->registers->find($id);
            $openedName = is_string($opened['name'] ?? null) ? $opened['name'] : 'Register';
            $this->drawers->use($slug, $sessionId, $openedName);
        } catch (DatabaseRefusal $refusal) {
            $this->hub->show($slug, $this->app->catchRefusal($refusal), 'register-open', $id, '', $draft);

            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub->show($slug, $error->getMessage(), 'register-open', $id, '', $draft);

            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register opened.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/close', ['POST'])]
    public function close(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->registers->find($id) === null) {
            $this->app->notFound();
        }
        $confirmed = $this->app->request()->field('confirm_variance') === 'yes';
        $draft = [];
        try {
            $counts = DenominationCount::fromRequest($this->app->request());
            $cash = DenominationCount::money($this->app->request()->field('counted_cash'), true);
            $card = DenominationCount::counted($this->app->request()->field('counted_card'));
            $checks = DenominationCount::counted($this->app->request()->field('counted_checks'));
            $draft = [
                'counts' => $counts,
                'cash' => DenominationCount::dollars($cash),
                'card' => DenominationCount::dollars($card),
                'checks' => DenominationCount::dollars($checks),
            ];
            $closed = $this->sessions->close($id, $counts, $cash, $card, $checks, $confirmed, $this->mall->today());
            $this->drawers->clear($slug, $closed);
        } catch (DatabaseRefusal $refusal) {
            $message = $this->app->catchRefusal($refusal);
            $confirm = $this->confirm($message, $confirmed);
            $this->hub->show($slug, $confirm === 'yes' ? null : $this->closeMessage($message), 'close-' . $id, $id, $confirm, $draft);

            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub->show($slug, $error->getMessage(), 'close-' . $id, $id, '', $draft);

            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register closed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/drop', ['POST'])]
    public function drop(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->registers->find($id) === null) {
            $this->app->notFound();
        }
        $postingDate = $this->app->request()->field('posting_date');
        try {
            if ($postingDate > $this->mall->today()) {
                throw new DatabaseRefusal('A cash drop cannot be dated after today.');
            }
            $this->sessions->drop(
                $id,
                DenominationCount::money($this->app->request()->field('amount'), false),
                $postingDate
            );
        } catch (DatabaseRefusal $refusal) {
            $this->hub->show($slug, $this->app->catchRefusal($refusal), 'drop-' . $id, $id, '');

            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub->show($slug, $error->getMessage(), 'drop-' . $id, $id, '');

            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Cash dropped.');
    }

    private function closeMessage(string $message): string
    {
        if ($message === 'close is refused while a ticket is open') {
            return 'A sale is still open on this register. Finish it or cancel it, then close.';
        }

        return $message;
    }

    private function confirm(string $message, bool $confirmed): string
    {
        if (!$confirmed && $message === 'counted cash does not match the expected drawer') {
            return 'yes';
        }

        return '';
    }
}
