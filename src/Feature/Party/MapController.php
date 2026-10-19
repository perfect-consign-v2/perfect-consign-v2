<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Scalar\RowValue;

final class MapController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly MapStore $maps,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $floors = $this->maps->floors();
        $floorId = $this->floorId($floors);
        $this->app->show('Feature/Party/map', [
            'title' => 'Booth map',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'tiles' => $this->tiles($floorId),
            'floors' => $floors,
            'floorId' => $floorId,
            'floorWidth' => $this->mall->floorSize()['width'],
            'floorDepth' => $this->mall->floorSize()['depth'],
            'today' => $this->mall->today(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map/edit', ['GET'])]
    public function edit(string $slug): void
    {
        $this->editor($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map/edit', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->maps->savePlacements($this->placements());
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $floor = $this->app->request()->field('floor');
            $target = '/' . $slug . '/map/edit';
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $floor) === 1) {
                $target .= '?floor=' . $floor;
            }
            $this->app->redirect($target, 'Map saved.');
        }
        $this->editor($slug, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map/floors', ['POST'])]
    public function addFloor(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        $id = null;
        try {
            $id = $this->maps->addFloor($this->app->request()->field('name'));
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null && is_string($id)) {
            $this->app->redirect('/' . $slug . '/map/edit?floor=' . $id, 'Floor added.');
        }
        $this->editor($slug, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map/floors/([0-9a-fA-F-]{36})', ['POST'])]
    public function renameFloor(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->maps->renameFloor($id, $this->app->request()->field('name'));
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/map/edit?floor=' . $id, 'Floor renamed.');
        }
        $this->editor($slug, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map/floors/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function removeFloor(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->maps->removeFloor($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/map/edit', 'Floor removed.');
        }
        $this->editor($slug, $error);
    }

    private function editor(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $floors = $this->maps->floors();
        $floorId = $this->floorId($floors);
        $booths = [];
        foreach ($this->parties->booths() as $booth) {
            $booths[] = [
                'id' => RowValue::string($booth['id'] ?? null),
                'code' => RowValue::string($booth['code'] ?? null),
                'name' => RowValue::string($booth['name'] ?? null),
                'width' => self::optionalFeet($booth['width_ft'] ?? null),
                'depth' => self::optionalFeet($booth['depth_ft'] ?? null),
                'x' => self::optionalFeet($booth['x_ft'] ?? null),
                'z' => self::optionalFeet($booth['z_ft'] ?? null),
                'floorId' => RowValue::optionalString($booth['floor_id'] ?? null),
                'status' => BoothMap::occupancy($this->maps->stays(), RowValue::string($booth['id'] ?? null), $this->mall->today())['status'],
            ];
        }
        $this->app->show('Feature/Party/map-edit', [
            'title' => 'Edit booth map',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'floors' => $floors,
            'floorId' => $floorId,
            'floorWidth' => $this->mall->floorSize()['width'],
            'floorDepth' => $this->mall->floorSize()['depth'],
            'defaultWidth' => $this->mall->boothSize()['width'],
            'defaultDepth' => $this->mall->boothSize()['depth'],
            'booths' => $booths,
            'error' => $error,
        ]);
    }

    /**
     * @return list<array{id: string, code: string, name: string, x: int, z: int, width: int, depth: int, status: string, vendor: ?string, incoming: ?string, label: string}>
     */
    private function tiles(?string $floorId): array
    {
        $booths = [];
        foreach ($this->parties->booths() as $booth) {
            $x = self::optionalFeet($booth['x_ft'] ?? null);
            $z = self::optionalFeet($booth['z_ft'] ?? null);
            $onFloor = RowValue::optionalString($booth['floor_id'] ?? null) === $floorId;
            if (!$onFloor) {
                $x = null;
                $z = null;
            }
            $booths[] = [
                'id' => RowValue::string($booth['id'] ?? null),
                'code' => RowValue::string($booth['code'] ?? null),
                'name' => RowValue::string($booth['name'] ?? null),
                'width' => self::optionalFeet($booth['width_ft'] ?? null),
                'depth' => self::optionalFeet($booth['depth_ft'] ?? null),
                'x' => $x,
                'z' => $z,
            ];
        }

        return BoothMap::tiles(
            $booths,
            $this->maps->stays(),
            $this->mall->today(),
            $this->mall->boothSize()['width'],
            $this->mall->boothSize()['depth']
        );
    }

    /**
     * @param list<array{id: string, name: string, sort: int}> $floors
     */
    private function floorId(array $floors): ?string
    {
        $requested = $this->app->request()->query('floor');
        if ($requested === '') {
            $requested = $this->app->request()->field('floor');
        }
        foreach ($floors as $floor) {
            if ($floor['id'] === $requested) {
                return $floor['id'];
            }
        }

        return $floors[0]['id'] ?? null;
    }

    /**
     * @return list<array{id: string, floorId: string, x: int, z: int, width: ?int, depth: ?int}>
     */
    private function placements(): array
    {
        $raw = $this->app->request()->field('layout');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('the map layout is missing');
        }
        $placed = [];
        $seen = [];
        foreach ($decoded as $row) {
            $placed[] = $this->placement($row, $seen);
        }

        return $placed;
    }

    /**
     * @param array<string, true> $seen
     * @return array{id: string, floorId: string, x: int, z: int, width: ?int, depth: ?int}
     */
    private function placement(mixed $row, array &$seen): array
    {
        if (!is_array($row)) {
            throw new \InvalidArgumentException('the map layout is missing');
        }
        $id = $this->layoutId($row['id'] ?? null);
        $floorId = $this->layoutId($row['floorId'] ?? null);
        if (isset($seen[$id])) {
            throw new \InvalidArgumentException('a booth is on the map twice');
        }
        $seen[$id] = true;
        $width = self::optionalFeet($row['width'] ?? null);
        $depth = self::optionalFeet($row['depth'] ?? null);
        $this->pairedFeet($width, $depth);

        return [
            'id' => $id,
            'floorId' => $floorId,
            'x' => self::feet($row['x'] ?? null),
            'z' => self::feet($row['z'] ?? null),
            'width' => $width,
            'depth' => $depth,
        ];
    }

    private function layoutId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            throw new \InvalidArgumentException('the map layout is missing');
        }

        return $value;
    }

    private function pairedFeet(?int $width, ?int $depth): void
    {
        if (($width === null) !== ($depth === null)) {
            throw new \InvalidArgumentException('set both booth dimensions or leave both empty');
        }
    }

    private static function feet(mixed $value): int
    {
        $feet = self::optionalFeet($value);
        if ($feet === null) {
            throw new \InvalidArgumentException('booth place is whole feet');
        }

        return $feet;
    }

    private static function optionalFeet(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return RowValue::integer($value);
    }
}
