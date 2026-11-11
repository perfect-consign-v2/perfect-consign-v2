<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\BasisPoints;

final class VendorForm
{
    private readonly VendorFormScalars $scalars;

    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly SellerPaths $sellerPaths,
        ?VendorFormScalars $scalars = null,
    ) {
        $this->scalars = $scalars ?? new VendorFormScalars();
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
     * @return array{
     *     code: string,
     *     first_name: string,
     *     last_name: string,
     *     phone: string,
     *     email: string,
     *     address: string,
     *     city: string,
     *     state: string,
     *     postal: string,
     *     phones: list<string>,
     *     emails: list<string>,
     *     mailing_address: string,
     *     mailing_city: string,
     *     mailing_state: string,
     *     mailing_postal: string,
     *     mailing_same: string
     * }
     */
    public function postedVendor(): array
    {
        return [
            'code' => $this->app->request()->field('code'),
            'first_name' => $this->app->request()->field('first_name'),
            'last_name' => $this->app->request()->field('last_name'),
            'phone' => $this->app->request()->field('phone'),
            'email' => $this->app->request()->field('email'),
            'address' => $this->app->request()->field('address'),
            'city' => $this->app->request()->field('city'),
            'state' => $this->app->request()->field('state'),
            'postal' => $this->app->request()->field('postal'),
            'phones' => $this->postedList('phones'),
            'emails' => $this->postedList('emails'),
            'mailing_address' => $this->app->request()->field('mailing_address'),
            'mailing_city' => $this->app->request()->field('mailing_city'),
            'mailing_state' => $this->app->request()->field('mailing_state'),
            'mailing_postal' => $this->app->request()->field('mailing_postal'),
            'mailing_same' => $this->app->request()->field('mailing_same'),
        ];
    }

    /**
     * Builds a vendor draft from the current POST (identity + facts).
     */
    public function postedDraft(): VendorDraft
    {
        $posted = $this->postedVendor();
        $facts = $this->postedFacts();

        return new VendorDraft([
            ...$posted,
            'agreement_starts_on' => $facts['agreement_starts_on'],
            'agreement_ends_on' => $facts['agreement_ends_on'],
            'tax_id' => $facts['tax_id'],
            'notes' => $facts['notes'],
            'tax_exempt' => $facts['tax_exempt'],
            'tax_exempt_number' => $facts['tax_exempt_number'],
            'business_name' => $this->app->request()->field('business_name'),
            'discount_by' => $facts['discount_by'],
            'keep_days' => $facts['keep_days'],
            'expiry_action' => $facts['expiry_action'],
        ], $this->storedTake($facts['store_take']));
    }

    /**
     * @return array<string, mixed>
     */
    public function postedFormValues(?string $id = null): array
    {
        $posted = $this->postedVendor();
        $facts = $this->postedFacts();
        $values = [
            ...$posted,
            'store_take' => $facts['store_take'],
            'agreement_starts_on' => $facts['agreement_starts_on'],
            'agreement_ends_on' => $facts['agreement_ends_on'],
            'tax_id' => $facts['tax_id'],
            'notes' => $facts['notes'],
            'tax_exempt' => $facts['tax_exempt'],
            'tax_exempt_number' => $facts['tax_exempt_number'],
            'discount_by' => $facts['discount_by'],
            'keep_days' => $facts['keep_days'],
            'expiry_action' => $facts['expiry_action'],
        ];
        if ($id !== null) {
            $values['id'] = $id;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function postedList(string $name): array
    {
        $raw = $this->app->request()->post[$name] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return $list;
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
            'title' => $editing && $named !== ''
                ? $named
                : ($editing
                    ? $this->mall->getPartyLabel('Edit vendor', 'Edit consignor')
                    : $this->mall->getPartyLabel('New vendor', 'New consignor')),
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'storeTake' => $shown['take'],
            'defaultStoreTake' => $this->scalars->percent($mallBasis),
            'overridden' => $shown['overridden'],
            'error' => $error,
            'sellerPrefix' => $this->sellerPaths->prefix(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $vendor
     * @return array{take: string, overridden: bool}
     */
    private function shownTake(?array $vendor, int $mallBasis): array
    {
        $shownTake = $this->scalars->percent($mallBasis);
        if (is_array($vendor) && array_key_exists('store_take', $vendor) && is_string($vendor['store_take'])) {
            return ['take' => $vendor['store_take'] !== '' ? $vendor['store_take'] : $shownTake, 'overridden' => false];
        }
        if (is_array($vendor) && array_key_exists('commission_bps', $vendor) && $vendor['commission_bps'] !== null) {
            return [
                'take' => $this->scalars->percent($vendor['commission_bps']),
                'overridden' => $this->scalars->points($vendor['commission_bps']) !== $mallBasis,
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
        return (new VendorFormFill())->apply($vendor, $stored, $mallBasis, $this->scalars);
    }

    /**
     * @param array<string, mixed> $vendor
     * @param array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null $quoted
     * @return array<string, mixed>
     */
    public function figures(array $vendor, ?array $quoted): array
    {
        return (new VendorFigures())->apply($vendor, $quoted);
    }
}
