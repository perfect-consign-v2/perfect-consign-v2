<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Scalar\RowValue;

final class IntakeController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly IntakeCatalog $catalog,
        private readonly NameListStore $lists,
        private readonly MallSettings $mall,
        private readonly StoreTake $take,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/vendors\.json', ['GET'])]
    public function findVendors(string $slug): void
    {
        $this->app->open($slug, true);
        $code = trim($this->app->request()->query('code'));
        $match = $code === '' ? null : $this->catalog->vendorByCode($code);
        $found = $code === '' ? $this->catalog->searchVendors($this->app->request()->query('q')) : ($match === null ? [] : [$match]);
        $rows = [];
        foreach ($found as $row) {
            $code = RowValue::string($row['code'] ?? null);
            $name = RowValue::string($row['name'] ?? null);
            $rows[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => $code,
                'name' => $name,
                'label' => $this->catalog->label($code, $name),
                'take' => $this->take->format(RowValue::integer($row['take_bps'] ?? null)),
            ];
        }
        $this->sendJson($rows);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/brands\.json', ['GET'])]
    public function findBrands(string $slug): void
    {
        $this->app->open($slug, true);
        if (!$this->mall->enabled('use_brand')) {
            $this->app->notFound();
        }
        $rows = [];
        foreach ($this->catalog->searchBrands($this->app->request()->query('q')) as $row) {
            $rows[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
            ];
        }
        $this->sendJson($rows);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/brands', ['POST'])]
    public function addBrand(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if (!$this->mall->enabled('use_brand')) {
            $this->app->notFound();
        }
        $name = $this->app->request()->field('name');
        if ($name === '') {
            $this->sendJson(['error' => 'Enter a brand name.'], 422);
        }
        try {
            $id = $this->catalog->addBrand($name);
        } catch (DatabaseRefusal $refusal) {
            $this->sendJson(['error' => $this->brandError($this->app->catchRefusal($refusal))], 422);
        }
        $this->sendJson(['id' => $id, 'name' => $name]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/(colors|categories|sizes|conditions|departments)\.json', ['GET'])]
    public function findNames(string $slug, string $catalog): void
    {
        $this->app->open($slug, true);
        $list = $this->openList($catalog);
        $rows = [];
        foreach ($this->lists->search($list, $this->app->request()->query('q')) as $row) {
            $rows[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
            ];
        }
        $this->sendJson($rows);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/(colors|categories|sizes|conditions|departments)', ['POST'])]
    public function addName(string $slug, string $catalog): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $list = $this->openList($catalog);
        $name = $this->app->request()->field('name');
        if ($name === '') {
            $this->sendJson(['error' => 'Enter a ' . $list->singular . ' name.'], 422);
        }
        try {
            $id = $this->lists->add($list, $name);
        } catch (DatabaseRefusal $refusal) {
            $message = $this->app->catchRefusal($refusal);
            if (str_contains($message, $list->table . '_name_key')) {
                $message = 'That name is already used.';
            }
            $this->sendJson(['error' => $message], 422);
        }
        $this->sendJson(['id' => $id, 'name' => $name]);
    }

    private function openList(string $catalog): NameList
    {
        $list = NameList::fromPath($catalog);
        if ($list === null || !$this->mall->enabled($list->flag)) {
            $this->app->notFound();
        }

        return $list;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function sendJson(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            http_response_code(500);
            echo '{"error":"The list could not be sent."}';
        }
        throw new ResponseSent('json');
    }

    private function brandError(string $message): string
    {
        if (str_contains($message, 'brands_name_key')) {
            return 'That name is already used.';
        }
        if (str_contains($message, 'brands_name_chk')) {
            return 'Enter a brand name.';
        }

        return $message;
    }
}
