<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class BoothController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $booths,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Party/booths', [
            'title' => 'Booths',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booths' => $this->booths->booths(),
            'defaultWidth' => $this->mall->boothWidth(),
            'defaultDepth' => $this->mall->boothDepth(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->form($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, null, function (): void {
            $size = $this->size();
            $this->booths->addBooth(
                $this->app->request()->field('code'),
                $this->app->request()->field('name'),
                $size['width'],
                $size['depth']
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $booth = $this->booths->findBooth($id);
        if ($booth === null) {
            $this->app->notFound();
        }
        $this->form($slug, $booth, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->change($slug, $id, function () use ($id): void {
            $size = $this->size();
            $this->booths->saveBooth(
                $id,
                $this->app->request()->field('code'),
                $this->app->request()->field('name'),
                $size['width'],
                $size['depth']
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->booths->removeBooth($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/booths');
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Party/booths', [
            'title' => 'Booths',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booths' => $this->booths->booths(),
            'defaultWidth' => $this->mall->boothWidth(),
            'defaultDepth' => $this->mall->boothDepth(),
            'error' => $error,
        ]);
    }

    private function change(string $slug, ?string $id, callable $action): void
    {
        $this->app->open($slug, true);
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/booths');
        }
        $booth = [
            'code' => $this->app->request()->field('code'),
            'name' => $this->app->request()->field('name'),
            'width_ft' => $this->app->request()->field('width_ft'),
            'depth_ft' => $this->app->request()->field('depth_ft'),
        ];
        if ($id !== null) {
            $booth['id'] = $id;
        }
        $this->form($slug, $booth, $error);
    }

    /**
     * @param array<string, mixed>|null $booth
     */
    private function form(string $slug, ?array $booth, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Party/booth-form', [
            'title' => $booth !== null && isset($booth['id']) ? 'Edit booth' : 'New booth',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booth' => $booth,
            'defaultWidth' => $this->mall->boothWidth(),
            'defaultDepth' => $this->mall->boothDepth(),
            'error' => $error,
        ]);
    }

    /**
     * @return array{width: ?int, depth: ?int}
     */
    private function size(): array
    {
        $width = $this->feet($this->app->request()->field('width_ft'));
        $depth = $this->feet($this->app->request()->field('depth_ft'));
        if ($width === false || $depth === false) {
            throw new \InvalidArgumentException('booth size is whole feet');
        }
        if (($width === null) !== ($depth === null)) {
            throw new \InvalidArgumentException('set both booth dimensions or leave both empty');
        }

        return ['width' => $width, 'depth' => $depth];
    }

    private function feet(string $value): int|false|null
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            return false;
        }

        return (int) $value;
    }
}
