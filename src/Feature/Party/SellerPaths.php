<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Mall\MallSettings;

/** Mode-correct seller URL prefix: /vendors (mall) or /consignors (consignment-only). */
final class SellerPaths
{
    public function __construct(private readonly MallSettings $mall)
    {
    }

    public function prefix(): string
    {
        return $this->mall->isConsignmentOnly() ? 'consignors' : 'vendors';
    }

    public function list(string $slug): string
    {
        return '/' . $slug . '/' . $this->prefix();
    }

    public function createForm(string $slug): string
    {
        return $this->list($slug) . '/new';
    }

    public function createAction(string $slug): string
    {
        return $this->list($slug);
    }

    public function record(string $slug, string $vendorId): string
    {
        return $this->list($slug) . '/' . $vendorId;
    }

    public function edit(string $slug, string $vendorId): string
    {
        return $this->record($slug, $vendorId) . '/edit';
    }

    public function inventoryJson(string $slug): string
    {
        return '/' . $slug . '/inventory/' . $this->prefix() . '.json';
    }
}
