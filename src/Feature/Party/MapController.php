<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
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
        $today = $this->mall->today();
        $booths = [];
        foreach ($this->parties->booths() as $booth) {
            $booths[] = [
                'id' => RowValue::string($booth['id'] ?? null),
                'code' => RowValue::string($booth['code'] ?? null),
                'name' => RowValue::string($booth['name'] ?? null),
                'width' => self::optionalFeet($booth['width_ft'] ?? null),
                'depth' => self::optionalFeet($booth['depth_ft'] ?? null),
            ];
        }
        $tiles = BoothMap::tiles(
            $booths,
            $this->maps->stays(),
            $today,
            $this->mall->boothWidth(),
            $this->mall->boothDepth()
        );
        $this->app->show('Feature/Party/map', [
            'title' => 'Booth map',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'tiles' => $tiles,
            'today' => $today,
        ]);
    }

    private static function optionalFeet(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return RowValue::integer($value);
    }
}
