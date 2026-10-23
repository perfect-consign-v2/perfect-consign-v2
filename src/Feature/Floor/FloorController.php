<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Floor;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class FloorController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Statements $statements,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/floor', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Floor/floor', [
            'title' => 'Floor',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'findings' => $this->rows(
                'SELECT finding.kind, finding.noted_on::text AS noted_on, finding.noted_by, item.code
                 FROM floor_findings AS finding
                 JOIN items AS item ON item.id = finding.item_id
                 ORDER BY finding.noted_on DESC'
            ),
            'pickups' => $this->rows(
                'SELECT pickup.picked_up_on::text AS picked_up_on, pickup.received_by, item.code, vendor.name
                 FROM pickups AS pickup
                 JOIN items AS item ON item.id = pickup.item_id
                 JOIN vendors AS vendor ON vendor.id = pickup.vendor_id
                 ORDER BY pickup.picked_up_on DESC'
            ),
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/floor', ['POST'])]
    public function note(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $kind = $request->field('kind');
        if ($kind !== 'missing' && $kind !== 'misplaced') {
            $this->app->redirect('/' . $slug . '/floor', 'Choose missing or misplaced.');
        }
        $this->statements->change(
            'INSERT INTO floor_findings (item_id, kind, noted_on, noted_by)
             SELECT item.id, ?, ?::date, ?
             FROM items AS item
             WHERE item.code = ?',
            [$kind, $request->field('noted_on'), $request->field('noted_by'), $request->field('code')]
        );
        $this->app->redirect('/' . $slug . '/floor', 'Floor note saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pickups', ['POST'])]
    public function pickup(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $this->statements->change(
            'INSERT INTO pickups (item_id, vendor_id, picked_up_on, received_by)
             SELECT item.id, item.vendor_id, ?::date, ?
             FROM items AS item
             WHERE item.code = ? AND item.vendor_id IS NOT NULL',
            [$request->field('picked_up_on'), $request->field('received_by'), $request->field('code')]
        );
        $this->app->redirect('/' . $slug . '/floor', 'Pickup saved.');
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $sql): array
    {
        $listed = [];
        foreach ($this->statements->rows($sql) as $row) {
            $clean = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $clean[$key] = RowValue::string($value);
                }
            }
            $listed[] = $clean;
        }

        return $listed;
    }
}
