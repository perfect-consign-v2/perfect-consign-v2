<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;

/** Cell HTML for one item. The page shell and the rows JSON both use this. */
final class ItemCells
{
    private const string BLANK = '—';

    /**
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $columns
     * @return list<string>
     */
    public function line(array $item, array $columns, ItemCellContext $context, string $audience): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $cells[] = $this->cell($item, $column, $context, $audience);
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $column
     */
    private function cell(array $item, array $column, ItemCellContext $context, string $audience): string
    {
        if (($column['select'] ?? false) === true) {
            return $this->check($item);
        }
        if (($column['actions'] ?? false) === true) {
            return $audience === 'vendor' ? $this->vendorEdit($item, $context) : $this->staffActions($item, $context);
        }
        $key = $column['key'] ?? '';

        return is_string($key) ? $this->field($key, $item, $context, $audience) : '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function field(string $key, array $item, ItemCellContext $context, string $audience): string
    {
        $named = $this->named($key, $item, $context, $audience);
        if ($named !== null) {
            return $named;
        }
        $shown = $this->shown($key, $item);
        if ($shown !== null) {
            return $shown;
        }

        return $this->measured($key, $item);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function shown(string $key, array $item): ?string
    {
        return match ($key) {
            'barcode', 'description' => View::text($this->text($item, $key)),
            'status' => View::text($this->text($item, 'status_label')),
            'intake_on' => View::date($this->text($item, 'intake_on')),
            'expires_on' => $this->expires($item),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function measured(string $key, array $item): string
    {
        return match ($key) {
            'take' => $this->take($item),
            'quantity' => '<span class="num">' . View::text((string) $this->whole($item, 'quantity')) . '</span>',
            'price' => View::text(View::money(View::minor($item['price_minor'] ?? 0))),
            'days' => $this->days($item),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function named(string $key, array $item, ItemCellContext $context, string $audience): ?string
    {
        if ($key === 'code') {
            return $audience === 'vendor' ? $this->vendorCode($item) : $this->staffCode($item, $context);
        }
        if ($key === 'vendor') {
            return $this->seller($item, $context);
        }
        if (!in_array($key, ['brand', 'color', 'category', 'size', 'condition', 'department'], true)) {
            return null;
        }

        return $this->blankable($this->text($item, $key . '_name'));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function check(array $item): string
    {
        $id = $this->text($item, 'id');
        $code = $this->text($item, 'code');

        return '<input form="stickers" type="checkbox" name="item[]" value="' . View::text($id)
            . '" aria-label="Print ' . View::text($code) . '">';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function staffCode(array $item, ItemCellContext $context): string
    {
        $id = $this->text($item, 'id');

        return '<a class="strong" href="/' . View::text($context->slug) . '/inventory/' . View::text($id) . '/edit">'
            . View::text($this->text($item, 'code')) . '</a>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function vendorCode(array $item): string
    {
        return '<span class="strong">' . View::text($this->text($item, 'code')) . '</span>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function seller(array $item, ItemCellContext $context): string
    {
        $id = $this->text($item, 'vendor_id');
        $label = $this->text($item, 'vendor_label');
        if ($id === '') {
            return View::text($label !== '' ? $label : self::BLANK);
        }

        return '<a class="strong" href="/' . View::text($context->slug) . '/' . View::text($context->sellerPath) . '/'
            . View::text($id) . '">' . View::text($label) . '</a>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function expires(array $item): string
    {
        $expires = $this->text($item, 'expires_on');
        if ($expires === '') {
            return '<span class="muted">' . self::BLANK . '</span>';
        }

        return View::date($expires);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function take(array $item): string
    {
        $html = View::text($this->text($item, 'take_label')) . '%';
        if (($item['overridden'] ?? false) === true) {
            return $html . ' <span class="badge badge-warning">Overridden</span>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function days(array $item): string
    {
        $tier = $item['age_tier'] ?? null;
        $days = $item['days_on_floor'] ?? null;
        if (is_int($tier) && is_int($days)) {
            return '<span class="num vendor-days">' . View::text((string) $days) . '</span>';
        }

        return '<span class="num vendor-days"><span class="muted">' . self::BLANK . '</span></span>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function staffActions(array $item, ItemCellContext $context): string
    {
        $id = $this->text($item, 'id');
        $edit = '<a class="btn btn-sm btn-ghost" href="/' . View::text($context->slug) . '/inventory/'
            . View::text($id) . '/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>';
        if ($context->confirm === strtolower($id)) {
            return $edit . $this->confirm($id, $context);
        }
        if (($item['deletable'] ?? false) !== true) {
            return $edit;
        }

        return $edit . '<a class="btn btn-sm" href="' . View::text($this->deleteUrl($id, $context))
            . '"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</a>';
    }

    private function confirm(string $id, ItemCellContext $context): string
    {
        return '<span>Delete this item?</span>'
            . '<a class="btn btn-sm btn-ghost" href="' . View::text($this->cancelUrl($context)) . '">Cancel</a>'
            . '<form method="post" action="/' . View::text($context->slug) . '/inventory/' . View::text($id) . '/delete">'
            . '<input type="hidden" name="csrf" value="' . View::text($context->csrf) . '">'
            . '<button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>'
            . '</form>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function vendorEdit(array $item, ItemCellContext $context): string
    {
        $path = '/' . $context->slug . '/vendor/inventory/' . $this->text($item, 'id') . '/edit';

        return '<a href="' . View::text(VendorPreview::with($path, $context->preview)) . '">Edit</a>';
    }

    private function deleteUrl(string $id, ItemCellContext $context): string
    {
        $ask = $this->keep($context);
        $ask['confirm'] = $id;

        return '/' . $context->slug . '/inventory?' . http_build_query($ask);
    }

    private function cancelUrl(ItemCellContext $context): string
    {
        $keep = $this->keep($context);
        if ($keep === []) {
            return '/' . $context->slug . '/inventory';
        }

        return '/' . $context->slug . '/inventory?' . http_build_query($keep);
    }

    /**
     * @return array<string, string>
     */
    private function keep(ItemCellContext $context): array
    {
        $keep = [];
        if ($context->stock !== '') {
            $keep['stock'] = $context->stock;
        }
        if ($context->query !== '') {
            $keep['q'] = $context->query;
        }

        return $keep;
    }

    private function blankable(string $value): string
    {
        if ($value === '') {
            return '<span class="muted">' . self::BLANK . '</span>';
        }

        return View::text($value);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function text(array $item, string $key): string
    {
        $value = $item[$key] ?? '';
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function whole(array $item, string $key): int
    {
        $value = $item[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
