<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelRequest
{
    public function __construct(private readonly LabelCatalog $catalog)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{stock: LabelStock, copies: int, row: int, column: int, ids: list<string>}
     */
    public function read(array $post, string $fallback): array
    {
        $stock = $this->stock($post['stock'] ?? null, $fallback);
        $copies = $this->copies($post['copies'] ?? null);
        $ids = $this->ids($post['item'] ?? null);
        $this->cap(count($ids), $copies);

        return [
            'stock' => $stock,
            'copies' => $copies,
            'row' => $this->place($post['start_row'] ?? null, $stock->getRows()),
            'column' => $this->place($post['start_column'] ?? null, $stock->getColumns()),
            'ids' => $ids,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function withSelectionKey(array $data, string $raw): array
    {
        $key = InventorySelectionKey::fromRequest($raw);
        if ($key === null) {
            return $data;
        }
        $data['selectionStorageKey'] = $key;

        return $data;
    }

    private function stock(mixed $raw, string $fallback): LabelStock
    {
        $key = is_string($raw) ? trim($raw) : '';
        if ($key === '') {
            $key = $fallback;
        }
        if (!$this->catalog->isKnown($key)) {
            throw new \InvalidArgumentException('Choose the stickers to print.');
        }

        return $this->catalog->get($key);
    }

    private function copies(mixed $raw): int
    {
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,2}$/', trim($raw)) !== 1) {
            throw new \InvalidArgumentException('How many is a number from 1 to 999.');
        }

        return (int) trim($raw);
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Choose a piece.');
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $id = trim($value);
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
                throw new \InvalidArgumentException('Choose a piece.');
            }
            $ids[strtolower($id)] = $id;
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('Choose a piece.');
        }

        return array_values($ids);
    }

    private function cap(int $count, int $copies): void
    {
        if ($count > LabelCatalog::LIMIT || $copies > intdiv(LabelCatalog::LIMIT, $count)) {
            throw new \InvalidArgumentException('That is more than 240 stickers. Print fewer pieces or fewer copies.');
        }
    }

    private function place(mixed $raw, int $limit): int
    {
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,2}$/', trim($raw)) !== 1) {
            throw new \InvalidArgumentException('A new sheet starts at row 1, column 1.');
        }
        $value = (int) trim($raw);
        if ($value > $limit) {
            throw new \InvalidArgumentException('A new sheet starts at row 1, column 1.');
        }

        return $value;
    }
}
