<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\MallSale;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MallSaleController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Statements $statements,
        private readonly Actor $actor,
        private readonly AuditLog $audit,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/mall-sale', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/MallSale/mall-sale', [
            'title' => 'Mall sale',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'events' => $this->events(),
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/mall-sale', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $absorbed = $request->field('absorbed_by');
        $percent = $request->field('percent');
        if (($absorbed !== 'mall' && $absorbed !== 'vendor') || preg_match('/^\d+(\.\d{1,2})?$/', $percent) !== 1) {
            $this->app->redirect('/' . $slug . '/mall-sale', 'Name who absorbs the sale and the percent.');
        }
        $bps = (int) round(((float) $percent) * 100);
        if ($bps < 1 || $bps > 5000) {
            $this->app->redirect('/' . $slug . '/mall-sale', 'The percent has to be between 0 and 50.');
        }
        $name = $this->actor->name();
        $role = $this->actor->role();
        if ($name === '' || $role === '') {
            $this->app->redirect('/' . $slug . '/mall-sale', 'The signed-in person is missing.');
        }
        try {
            $this->statements->transaction(function () use ($request, $bps, $absorbed, $name, $role): void {
                $rows = $this->statements->bound(
                    'INSERT INTO mall_sales (name, starts_on, ends_on, percent_bps, absorbed_by)
                     VALUES (?, ?::date, ?::date, ?, ?)
                     RETURNING id::text AS id',
                    [$request->field('name'), $request->field('starts_on'), $request->field('ends_on'), $bps, $absorbed]
                );
                $this->audit->record($name, $role, 'mall_sale.create', 'mall_sale', RowValue::string($rows[0]['id'] ?? null));
            });
        } catch (DatabaseRefusal $refusal) {
            $this->app->redirect('/' . $slug . '/mall-sale', $this->app->catchRefusal($refusal));
        }
        $this->app->redirect('/' . $slug . '/mall-sale', 'Mall sale saved.');
    }

    /**
     * @return list<array{name: string, starts_on: string, ends_on: string, percent_bps: int, absorbed_by: string}>
     */
    private function events(): array
    {
        $listed = [];
        foreach ($this->statements->rows(
            'SELECT name, starts_on::text AS starts_on, ends_on::text AS ends_on, percent_bps, absorbed_by
             FROM mall_sales ORDER BY starts_on DESC'
        ) as $row) {
            $listed[] = [
                'name' => RowValue::string($row['name'] ?? null),
                'starts_on' => RowValue::string($row['starts_on'] ?? null),
                'ends_on' => RowValue::string($row['ends_on'] ?? null),
                'percent_bps' => RowValue::integer($row['percent_bps'] ?? null),
                'absorbed_by' => RowValue::string($row['absorbed_by'] ?? null),
            ];
        }

        return $listed;
    }
}
