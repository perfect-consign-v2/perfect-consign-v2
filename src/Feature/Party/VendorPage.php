<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Staff\StaffStore;

final class VendorPage
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly AssignmentBook $stays,
        private readonly MallSettings $mall,
        private readonly VendorRoster $roster,
        private readonly VendorBalances $balances,
        private readonly AssignmentOffer $offers,
        private readonly VendorForm $forms,
        private readonly StaffStore $staff,
    ) {
    }

    public function show(string $slug, string $id, ?string $error): void
    {
        $today = $this->mall->today();
        $assembled = $this->roster->assemble($this->stays->vendorRowsFor($id, $today), $today);
        $vendor = $assembled['vendors'][0] ?? null;
        $stored = $this->parties->findVendor($id);
        if ($vendor === null || $stored === null) {
            $this->app->notFound();
        }
        $vendor = $this->forms->filled($vendor, $stored, $this->mall->storeTake());
        $quoted = $this->balances->quote($id, $this->mall->today());
        $vendor = $this->forms->figures($vendor, $quoted);
        $vendor['clawback_minor'] = $this->balances->clawback($id);
        $vendor['year_paid_minor'] = $this->balances->yearPaid($id);
        $histories = $this->rentHistories($vendor);
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Party/vendor', [
            'title' => $vendor['name'],
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'booths' => $this->offers->booths($this->mall->today()),
            'signIn' => $this->staff->vendorSignIn($id),
            'rentHistories' => $histories,
            'today' => $this->mall->today(),
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $vendor
     * @return array<string, list<array{starts_on: string, amount_minor: int}>>
     */
    private function rentHistories(array $vendor): array
    {
        $histories = [];
        foreach ($this->stayRows($vendor) as $stay) {
            $stayId = $stay['id'] ?? null;
            if (!is_string($stayId) || $stayId === '') {
                continue;
            }
            $histories[$stayId] = $this->stays->rentHistory($stayId);
        }

        return $histories;
    }

    /**
     * @param array<string, mixed> $vendor
     * @return list<array<string, mixed>>
     */
    private function stayRows(array $vendor): array
    {
        $rows = [];
        foreach (['current', 'upcoming', 'past'] as $bucket) {
            $listed = $vendor[$bucket] ?? null;
            if (!is_array($listed)) {
                continue;
            }
            foreach ($listed as $stay) {
                $row = $this->stayRow($stay);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stayRow(mixed $stay): ?array
    {
        if (!is_array($stay)) {
            return null;
        }
        $row = [];
        foreach ($stay as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }
}
