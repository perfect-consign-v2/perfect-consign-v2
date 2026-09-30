<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\BasisPoints;

final class VendorForm
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
    ) {
    }

    public function vendorError(string $message): string
    {
        if (str_contains($message, 'vendors_code_key')) {
            return 'That code is already used.';
        }
        if (str_contains($message, 'items_vendor_id_fkey')) {
            return 'That vendor still has inventory.';
        }

        return $message;
    }

    public function storedTake(string $entered): ?int
    {
        if ($entered === '') {
            return null;
        }
        $basis = BasisPoints::percent($entered);
        if ($basis === null) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
        if ($basis === $this->mall->storeTake()) {
            return null;
        }

        return $basis;
    }

    public function points(mixed $basis): int
    {
        if (is_int($basis)) {
            return $basis;
        }
        if (is_string($basis) && preg_match('/^\d+$/', $basis) === 1) {
            return (int) $basis;
        }

        return 0;
    }

    public function percent(mixed $basis): string
    {
        $points = $this->points($basis);
        $whole = intdiv($points, 100);
        $fraction = $points % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }
        if ($fraction % 10 === 0) {
            return $whole . '.' . (string) intdiv($fraction, 10);
        }

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    public function plain(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public function enabled(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * @return array{
     *     store_take: string,
     *     agreement_starts_on: string,
     *     agreement_ends_on: string,
     *     tax_id: string,
     *     notes: string,
     *     tax_exempt: string,
     *     tax_exempt_number: string
     * }
     */
    public function postedFacts(): array
    {
        return [
            'store_take' => $this->app->request()->field('store_take'),
            'agreement_starts_on' => $this->app->request()->field('agreement_starts_on'),
            'agreement_ends_on' => $this->app->request()->field('agreement_ends_on'),
            'tax_id' => $this->app->request()->field('tax_id'),
            'notes' => $this->app->request()->field('notes'),
            'tax_exempt' => $this->app->request()->field('tax_exempt'),
            'tax_exempt_number' => $this->app->request()->field('tax_exempt_number'),
        ];
    }

    /**
     * @param array<string, mixed>|null $vendor
     */
    public function form(string $slug, ?array $vendor, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $mallBasis = $this->mall->storeTake();
        $shown = $this->shownTake($vendor, $mallBasis);
        $editing = $vendor !== null && isset($vendor['id']);
        $this->app->show('Feature/Party/vendor-form', [
            'title' => $editing ? 'Edit vendor' : 'New vendor',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'storeTake' => $shown['take'],
            'defaultStoreTake' => $this->percent($mallBasis),
            'overridden' => $shown['overridden'],
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed>|null $vendor
     * @return array{take: string, overridden: bool}
     */
    private function shownTake(?array $vendor, int $mallBasis): array
    {
        $shownTake = $this->percent($mallBasis);
        if (is_array($vendor) && array_key_exists('store_take', $vendor) && is_string($vendor['store_take'])) {
            return ['take' => $vendor['store_take'] !== '' ? $vendor['store_take'] : $shownTake, 'overridden' => false];
        }
        if (is_array($vendor) && array_key_exists('commission_bps', $vendor) && $vendor['commission_bps'] !== null) {
            return [
                'take' => $this->percent($vendor['commission_bps']),
                'overridden' => $this->points($vendor['commission_bps']) !== $mallBasis,
            ];
        }

        return ['take' => $shownTake, 'overridden' => false];
    }
}
