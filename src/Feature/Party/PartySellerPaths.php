<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Mall\MallSettings;

/** Seller list/record URL helpers for party screens. */
final class PartySellerPaths
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

    public function record(string $slug, string $vendorId): string
    {
        return $this->list($slug) . '/' . $vendorId;
    }

    public function edit(string $slug, string $vendorId): string
    {
        return $this->record($slug, $vendorId) . '/edit';
    }
}
