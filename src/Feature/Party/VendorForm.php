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
     *     tax_exempt_number: string,
     *     discount_by: string,
     *     keep_days: string,
     *     expiry_action: string
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
            'discount_by' => $this->app->request()->field('discount_by'),
            'keep_days' => $this->app->request()->field('keep_days'),
            'expiry_action' => $this->app->request()->field('expiry_action'),
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
        $named = is_array($vendor) && is_string($vendor['name'] ?? null) ? $vendor['name'] : '';
        $this->app->show('Feature/Party/vendor-form', [
            'title' => $editing && $named !== '' ? $named : ($editing ? 'Edit vendor' : 'New vendor'),
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

    /**
     * @param array<string, mixed> $vendor
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function filled(array $vendor, array $stored, int $mallBasis): array
    {
        $savedBasis = $stored['commission_bps'] ?? null;
        $vendor['store_take'] = $this->percent($savedBasis === null ? $mallBasis : $savedBasis);
        $vendor['store_take_overridden'] = $savedBasis !== null && $this->points($savedBasis) !== $mallBasis;
        $vendor['agreement_starts_on'] = $this->plain($stored['agreement_starts_on'] ?? null);
        $vendor['agreement_ends_on'] = $this->plain($stored['agreement_ends_on'] ?? null);
        $vendor['tax_id'] = $this->plain($stored['tax_id'] ?? null);
        $vendor['notes'] = $this->plain($stored['notes'] ?? null);
        $vendor['tax_exempt'] = $this->enabled($stored['tax_exempt'] ?? false);
        $vendor['tax_exempt_number'] = $this->plain($stored['tax_exempt_number'] ?? null);
        $vendor['business_name'] = $this->plain($stored['business_name'] ?? null);
        $vendor['discount_by'] = $this->plain($stored['discount_by'] ?? 'staff');
        $kept = $stored['keep_days'] ?? null;
        $vendor['keep_days'] = is_int($kept) ? (string) $kept : (is_string($kept) ? $kept : '');
        $vendor['expiry_action'] = $this->plain($stored['expiry_action'] ?? 'return');
        $vendor['email_bounced'] = $this->enabled($stored['email_bounced'] ?? false);
        $vendor['first_name'] = $this->plain($stored['first_name'] ?? null);
        $vendor['last_name'] = $this->plain($stored['last_name'] ?? null);
        $vendor['default_store_take'] = $this->percent($mallBasis);

        return $vendor;
    }

    /**
     * @param array<string, mixed> $vendor
     * @param array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null $quoted
     * @return array<string, mixed>
     */
    public function figures(array $vendor, ?array $quoted): array
    {
        $vendor['payable_minor'] = 0;
        $vendor['rent_minor'] = 0;
        $vendor['check_minor'] = 0;
        $vendor['owes_minor'] = 0;
        $vendor['settled_minor'] = 0;
        $vendor['rent_from_sales'] = false;
        if ($quoted === null) {
            return $vendor;
        }
        $vendor['payable_minor'] = $quoted['payable'];
        $vendor['rent_minor'] = $quoted['rent'];
        $vendor['check_minor'] = $quoted['check'];
        $vendor['owes_minor'] = $quoted['owes'];
        $vendor['settled_minor'] = $quoted['settled'];
        $vendor['rent_from_sales'] = $quoted['rent_from_sales'];

        return $vendor;
    }
}
