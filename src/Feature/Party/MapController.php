<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Scalar\RowValue;

final class MapController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly MapStore $maps,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/map', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $booths = [];
        foreach ($this->parties->booths() as $booth) {
            $booths[] = [
                'id' => RowValue::string($booth['id'] ?? null),
                'code' => RowValue::string($booth['code'] ?? null),
                'name' => RowValue::string($booth['name'] ?? null),
            ];
        }
        $tiles = BoothMap::tiles($booths, $this->maps->stays(), $today);
        $this->app->show('Feature/Party/map', [
            'title' => 'Booth map',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'tiles' => $tiles,
            'today' => $today,
        ]);
    }
}
