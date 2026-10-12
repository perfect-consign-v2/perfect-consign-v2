<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\WaitList;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class WaitListController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly WaitListStore $waitList,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list', ['GET'])]
    public function show(string $slug): void
    {
        $this->list($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->app->open($slug, true);
        $this->form($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list/([0-9a-fA-F-]{36})', ['GET'])]
    public function record(string $slug, string $id): void
    {
        $tenant = $this->app->open($slug, true);
        $entry = $this->waitList->card($id);
        if ($entry === null) {
            $this->app->notFound();
        }
        $this->app->show('Feature/WaitList/wait-list-record', [
            'title' => $entry['name'],
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'entry' => $entry,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $entry = $this->waitList->find($id);
        if ($entry === null) {
            $this->app->notFound();
        }
        $this->form($slug, $entry, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->change($slug, $id);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/wait-list/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->waitList->remove($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->entryError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/wait-list', 'Removed from the wait list.');
        }
        $this->list($slug, $error);
    }

    private function change(string $slug, ?string $id): void
    {
        $this->app->open($slug, true);
        $draft = $this->draft($id);
        $error = null;
        $saved = $id;
        try {
            $saved = $this->write($id, $draft);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->entryError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null && is_string($saved) && $saved !== '') {
            $this->app->redirect(
                '/' . $slug . '/wait-list/' . $saved,
                $id === null ? 'Added to the wait list.' : 'Wait list saved.'
            );
        }
        $this->form($slug, $draft, $error);
    }

    /**
     * @param array{id: string, first_name: string, last_name: string, email: string, phone: string, width_ft: string, depth_ft: string, status: string, notes: string} $draft
     */
    private function write(?string $id, array $draft): string
    {
        if ($id === null) {
            return $this->waitList->add(
                $draft['first_name'],
                $draft['last_name'],
                $draft['email'],
                $draft['phone'],
                $draft['width_ft'],
                $draft['depth_ft'],
                $draft['notes'],
            );
        }
        $this->waitList->save(
            $id,
            $draft['first_name'],
            $draft['last_name'],
            $draft['email'],
            $draft['phone'],
            $draft['width_ft'],
            $draft['depth_ft'],
            $draft['status'],
            $draft['notes'],
        );

        return $id;
    }

    /**
     * @return array{id: string, first_name: string, last_name: string, email: string, phone: string, width_ft: string, depth_ft: string, status: string, notes: string}
     */
    private function draft(?string $id): array
    {
        $status = $this->app->request()->field('status');

        return [
            'id' => $id ?? '',
            'first_name' => $this->app->request()->field('first_name'),
            'last_name' => $this->app->request()->field('last_name'),
            'email' => $this->app->request()->field('email'),
            'phone' => $this->app->request()->field('phone'),
            'width_ft' => $this->app->request()->field('width_ft'),
            'depth_ft' => $this->app->request()->field('depth_ft'),
            'status' => $status === '' ? 'waiting' : $status,
            'notes' => $this->app->request()->field('notes'),
        ];
    }

    /**
     * @param array<string, mixed>|null $entry
     */
    private function form(string $slug, ?array $entry, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $id = is_array($entry) ? ($entry['id'] ?? null) : null;
        $this->app->show('Feature/WaitList/wait-list-form', [
            'title' => is_string($id) && $id !== '' ? 'Edit wait list' : 'New wait list',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'entry' => $entry,
            'error' => $error,
        ]);
    }

    private function list(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $query = trim($this->app->request()->query('q'));
        $status = $this->standing($this->app->request()->query('status'));
        $entries = $this->waitList->entries($query, $status);
        $this->app->show('Feature/WaitList/wait-list', [
            'title' => 'Wait list',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'entries' => $entries,
            'query' => $query,
            'status' => $status,
            'counts' => $this->waitList->counts(),
            'total' => $this->waitList->count(),
            'confirm' => $this->confirming($entries, $this->app->request()->query('confirm')),
            'error' => $error,
        ]);
    }

    private function standing(string $value): string
    {
        if (in_array($value, ['waiting', 'contacted', 'placed', 'declined', 'all'], true)) {
            return $value;
        }

        return '';
    }

    /**
     * @param list<array{id: string, name: string, email: string, phone: string, size: string, status: string, status_label: string, status_badge: string, notes: string, waiting_since: string, waited: string}> $entries
     */
    private function confirming(array $entries, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($entries as $entry) {
            if (strtolower($entry['id']) === $needle) {
                return $entry['id'];
            }
        }

        return '';
    }

    private function entryError(string $message): string
    {
        $known = [
            'wait_list_open_email_key' => 'That email is already on the wait list.',
            'wait_list_email_chk' => 'that email is not an email address',
            'wait_list_phone_chk' => 'a phone number is required',
            'wait_list_first_name_chk' => 'a first name is required',
            'wait_list_last_name_chk' => 'a last name is required',
            'wait_list_size_chk' => 'preferred size is whole feet',
            'wait_list_status_chk' => 'that status is not on the wait list',
            'wait_list_notes_chk' => 'those notes are too long',
        ];
        $found = array_filter(
            $known,
            static fn (string $needle): bool => str_contains($message, $needle),
            ARRAY_FILTER_USE_KEY,
        );
        foreach ($found as $text) {
            return $text;
        }

        return $message;
    }
}
