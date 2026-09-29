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
        private readonly RegisterActivity $activity,
        private readonly ActiveRegister $drawers,
        private readonly RegisterHub $hub,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $close = $this->app->request()->query('close');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $close) !== 1) {
            $this->hub->show($slug, null, '', '', '');
            return;
        }
        $this->hub->show($slug, null, 'close-' . $close, $close, '');
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
        $this->hub->show($slug, null, 'register-rename', $id, '', ['name' => is_string($name) ? $name : '']);
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
            $this->hub->show($slug, $this->app->catchRefusal($refusal), 'register-rename', $id, '', ['name' => $name]);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->hub->show($slug, $error->getMessage(), 'register-rename', $id, '', ['name' => $name]);
            return;
        }
        $this->app->redirect('/' . $slug . '/registers', 'Register renamed.');
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
            $this->hub->show($slug, $this->app->catchRefusal($refusal), 'delete-' . $id, $id, '');
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
        $this->hub->show($slug, 'That register is not open.', '', '', '');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/registers/close-cancel', ['POST'])]
    public function cancelClose(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->app->releaseClose($slug);
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
}
