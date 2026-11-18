<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Vendor\FloorAge;
use PerfectConsign\Shared\Http\JsonResponse;
use PerfectConsign\Shared\Http\Request;

/** One JSON page of staff or vendor items. */
final class ItemBoard
{
    public function __construct(
        private readonly ItemQueries $queries,
        private readonly ItemListRows $rows,
        private readonly InventoryStore $items,
        private readonly MallSettings $mall,
    ) {
    }

    public function send(
        Request $request,
        string $slug,
        string $csrf,
        string $vendorId,
        string $preview,
        string $audience,
    ): void {
        $stock = $this->stock($request->query('stock'), $audience);
        $columns = $this->columns($audience);
        try {
            $asked = ItemPageQuery::from($request, $stock, $vendorId, $this->mall->today(), ItemColumns::keys($columns));
        } catch (\InvalidArgumentException $error) {
            JsonResponse::send(['error' => $error->getMessage()], 422, 'item rows failed');
        }
        $page = $this->queries->page($asked);
        $marked = $this->marked($page->rows, $audience);
        JsonResponse::send([
            'draw' => $asked->window->draw,
            'recordsTotal' => $page->total,
            'recordsFiltered' => $page->filtered,
            'data' => (new ItemRowsPayload())->pack(
                $marked,
                $columns,
                $this->context($request, $slug, $csrf, $stock, $marked, $preview),
                $audience,
            ),
        ], 200, 'item rows failed');
    }

    /**
     * @param list<array<string, mixed>> $marked
     */
    private function context(
        Request $request,
        string $slug,
        string $csrf,
        string $stock,
        array $marked,
        string $preview,
    ): ItemCellContext {
        return new ItemCellContext(
            $slug,
            $csrf,
            $stock,
            trim($request->query('q')),
            $this->confirm($marked, $request->query('confirm')),
            $this->mall->isConsignmentOnly() ? 'consignors' : 'vendors',
            $preview,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(string $audience): array
    {
        $brand = $this->mall->enabled('use_brand') ? 'show' : 'hide';
        $lists = $this->mall->getEnabledLists();
        if ($audience === 'vendor') {
            return ItemColumns::vendor($brand, $lists);
        }

        return ItemColumns::staff($brand, $lists, $this->mall->isConsignmentOnly() ? 'Consignor' : 'Vendor');
    }

    private function stock(string $raw, string $audience): string
    {
        $allowed = $audience === 'vendor'
            ? ['available', 'out', 'unprinted']
            : ['available', 'out', 'sold', 'unprinted'];

        return in_array($raw, $allowed, true) ? $raw : '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function marked(array $rows, string $audience): array
    {
        $marked = $this->rows->mark($rows, $audience === 'staff' ? $this->items->held($this->ids($rows)) : []);
        if ($audience !== 'vendor') {
            return $marked;
        }

        return $this->aged($marked);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function aged(array $items): array
    {
        $age = new FloorAge();
        $today = $this->mall->today();
        foreach ($items as $index => $item) {
            $intake = $item['intake_on'] ?? null;
            $days = $age->days(is_string($intake) && $intake !== '' ? $intake : null, $today);
            $quantity = $item['quantity'] ?? 0;
            $items[$index]['days_on_floor'] = $days;
            $items[$index]['age_tier'] = $age->tier($days, is_int($quantity) ? $quantity : 0);
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function confirm(array $items, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if (is_string($id) && strtolower($id) === $needle && ($item['deletable'] ?? false) === true) {
                return strtolower($id);
            }
        }

        return '';
    }
}
