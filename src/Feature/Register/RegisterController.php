<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class RegisterController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterStore $registers,
        private readonly RegisterSession $sessions,
        private readonly RegisterActivity $activity,
        private readonly ActiveRegister $drawers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $close = $this->app->request()->query('close');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $close) !== 1) {
            $this->hub($slug, null, '', '', false);
            return;
        }
        $this->hub($slug, null, 'close-' . $close, $close, false);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->form($slug, null, '', null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $name = $this->app->request()->field('name');
        try {
            $this->registers->add($name);
        } catch (DatabaseRefusal $refusal) {
            $this->form($slug, null, $name, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->form($slug, null, $name, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register added.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $register = $this->registers->find($id);
        if ($register === null) {
            $this->app->notFound();
        }
        $name = $register['name'] ?? '';
        $this->hub($slug, null, 'register-rename', $id, false, ['name' => is_string($name) ? $name : '']);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})', ['POST'])]
    public function rename(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->registers->find($id) === null) {
            $this->app->notFound();
        }
        $name = $this->app->request()->field('name');
        try {
            $this->registers->rename($id, $name);
        } catch (DatabaseRefusal $refusal) {
            $this->hub($slug, $this->app->catchRefusal($refusal), 'register-rename', $id, false, ['name' => $name]);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub($slug, $error->getMessage(), 'register-rename', $id, false, ['name' => $name]);
            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register renamed.');
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
            $this->hub($slug, $this->app->catchRefusal($refusal), 'register-open', $id, false, $draft);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub($slug, $error->getMessage(), 'register-open', $id, false, $draft);
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
            $closed = $this->sessions->close($id, $counts, $cash, $card, $checks, $confirmed, $this->app->today());
            $this->drawers->clear($slug, $closed);
        } catch (DatabaseRefusal $refusal) {
            $message = $this->app->catchRefusal($refusal);
            $confirm = !$confirmed && $message === 'counted cash does not match the expected drawer';
            $this->hub($slug, $confirm ? 'Counted cash does not match the drawer. Confirm the difference to close.' : $message, 'close-' . $id, $id, $confirm, $draft);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub($slug, $error->getMessage(), 'close-' . $id, $id, false, $draft);
            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register closed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function delete(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->registers->find($id) === null) {
            $this->app->notFound();
        }
        try {
            $this->registers->remove($id);
        } catch (DatabaseRefusal $refusal) {
            $this->hub($slug, $this->app->catchRefusal($refusal), 'delete-' . $id, $id, false);
            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register deleted.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/use', ['POST'])]
    public function use(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        foreach ($this->registers->cards() as $card) {
            if (($card['id'] ?? null) !== $id || ($card['open'] ?? false) !== true) {
                continue;
            }
            $sessionId = $card['session_id'] ?? '';
            $name = $card['name'] ?? '';
            if (!is_string($sessionId) || $sessionId === '' || !is_string($name)) {
                break;
            }
            $this->drawers->use($slug, $sessionId, $name);
            $this->app->redirect('/' . $slug . '/registers', $name . ' is the active register.');
        }
        if ($this->registers->find($id) === null) {
            $this->app->notFound();
        }
        $this->hub($slug, 'That register is not open.', '', '', false);
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
            if ($postingDate > $this->app->today()) {
                throw new DatabaseRefusal('A cash drop cannot be dated after today.');
            }
            $this->sessions->drop(
                $id,
                DenominationCount::money($this->app->request()->field('amount'), false),
                $postingDate
            );
        } catch (DatabaseRefusal $refusal) {
            $this->hub($slug, $this->app->catchRefusal($refusal), 'drop-' . $id, $id, false);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub($slug, $error->getMessage(), 'drop-' . $id, $id, false);
            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Cash dropped.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/([0-9a-fA-F-]{36})/shifts', ['GET'])]
    public function shifts(string $slug, string $id): void
    {
        $tenant = $this->app->open($slug, true);
        $register = $this->registers->find($id);
        if ($register === null) {
            $this->app->notFound();
        }
        $name = $register['name'] ?? '';
        $this->app->show('Feature/Register/shifts', [
            'title' => 'Shifts',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'registerId' => $id,
            'name' => is_string($name) ? $name : '',
            'shifts' => $this->activity->shifts($id),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/sessions/([0-9a-fA-F-]{36})', ['GET'])]
    public function tape(string $slug, string $sessionId): void
    {
        $tenant = $this->app->open($slug, true);
        $tape = $this->activity->tape($sessionId);
        if ($tape === null) {
            $this->app->notFound();
        }
        $this->app->show('Feature/Register/tape', [
            'title' => 'Z-tape',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'tape' => $tape,
        ]);
    }

    private function form(string $slug, ?string $id, string $name, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Register/register-form', [
            'title' => $id === null ? 'New register' : 'Rename register',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'registerId' => $id ?? '',
            'name' => $name,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function hub(
        string $slug,
        ?string $error,
        string $modal,
        string $registerId,
        bool $confirmVariance,
        array $draft = [],
    ): void {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Register/registers', [
            'title' => 'Registers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'registers' => $this->registers->cards(),
            'cashier' => $this->drawers->ownerName(),
            'denominations' => DenominationCount::LABELS,
            'modal' => $modal,
            'registerId' => $registerId,
            'confirmVariance' => $confirmVariance,
            'draft' => $draft,
            'error' => $error,
        ]);
    }
}
