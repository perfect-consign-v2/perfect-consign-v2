<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

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
        $x = $this->coordinate($this->app->request()->query('x'));
        $z = $this->coordinate($this->app->request()->query('z'));
        $booth = null;
        if (is_int($x) && is_int($z)) {
            $booth = [
                'code' => '',
                'name' => '',
                'width_ft' => '10',
                'depth_ft' => '10',
                'x_ft' => (string) $x,
                'z_ft' => (string) $z,
            ];
        }
        $this->form($slug, $booth, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, null, function (): void {
            $size = $this->size();
            $place = $this->place();
            $this->booths->addBooth(
                $this->app->request()->field('code'),
                $this->app->request()->field('name'),
                $size['width'],
                $size['depth'],
                $place['x'],
                $place['z']
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
            $place = $this->place();
            $this->booths->saveBooth(
                $id,
                $this->app->request()->field('code'),
                $this->app->request()->field('name'),
                $size['width'],
                $size['depth'],
                $place['x'],
                $place['z']
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
        $this->list($slug, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rates', ['POST'])]
    public function addRate(string $slug): void
    {
        $this->app->checkCsrf();
        $id = $this->app->request()->field('booth_id');
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->booths->addRate(
                $id,
                $this->app->request()->field('month_start'),
                Money::parseMinor($this->app->request()->field('amount'))
            );
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null && preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1) {
            $this->app->redirect('/' . $slug . '/booths/' . $id . '/edit');
        }
        $booth = preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1 ? $this->booths->findBooth($id) : null;
        if ($booth === null) {
            $this->list($slug, $error ?? 'that booth is not in this mall');
            return;
        }
        $this->form($slug, $booth, $error);
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
            'x_ft' => $this->app->request()->field('x_ft'),
            'z_ft' => $this->app->request()->field('z_ft'),
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
        $id = is_array($booth) ? ($booth['id'] ?? null) : null;
        $this->app->show('Feature/Party/booth-form', [
            'title' => is_string($id) && $id !== '' ? 'Edit booth' : 'New booth',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booth' => $booth,
            'rates' => is_string($id) && $id !== '' ? $this->ratesFor($id) : [],
            'defaultWidth' => $this->mall->boothWidth(),
            'defaultDepth' => $this->mall->boothDepth(),
            'error' => $error,
        ]);
    }

    private function list(string $slug, ?string $error): void
    {
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

    /**
     * @return list<array<string, mixed>>
     */
    private function ratesFor(string $boothId): array
    {
        $matched = [];
        foreach ($this->booths->rates() as $rate) {
            if (($rate['booth_id'] ?? null) === $boothId) {
                $matched[] = $rate;
            }
        }

        return $matched;
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

    /**
     * @return array{x: ?int, z: ?int}
     */
    private function place(): array
    {
        $x = $this->coordinate($this->app->request()->field('x_ft'));
        $z = $this->coordinate($this->app->request()->field('z_ft'));
        if ($x === false || $z === false) {
            throw new \InvalidArgumentException('booth position is whole feet');
        }
        if (($x === null) !== ($z === null)) {
            throw new \InvalidArgumentException('set both booth coordinates or leave both empty');
        }

        return ['x' => $x, 'z' => $z];
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

    private function coordinate(string $value): int|false|null
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(0|[1-9][0-9]{0,3})$/', $value) !== 1) {
            return false;
        }

        return (int) $value;
    }
}
