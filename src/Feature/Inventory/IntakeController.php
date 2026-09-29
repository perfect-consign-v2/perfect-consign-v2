<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Scalar\RowValue;

final class IntakeController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly IntakeCatalog $catalog,
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
