<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\PayoutStore;

final class VendorPage
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly MallSettings $mall,
        private readonly VendorRoster $roster,
        private readonly PayoutStore $payouts,
        private readonly AssignmentOffer $offers,
        private readonly VendorForm $forms,
    ) {
    }

    public function show(string $slug, string $id, ?string $error): void
    {
        $assembled = $this->roster->assemble(
            $this->parties->vendorRows(),
            $this->parties->booths(),
            $this->mall->today()
        );
        $vendor = null;
        foreach ($assembled['vendors'] as $candidate) {
            if ($candidate['id'] === $id) {
                $vendor = $candidate;
                break;
            }
        }
        $stored = $this->parties->findVendor($id);
        if ($vendor === null || $stored === null) {
            $this->app->notFound();
        }
        $vendor = $this->forms->filled($vendor, $stored, $this->mall->storeTake());
        $quoted = $this->payouts->quote($id, $this->mall->today());
        $vendor = $this->forms->figures($vendor, $quoted);
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Party/vendor', [
            'title' => $vendor['name'],
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'booths' => $this->offers->booths(),
            'error' => $error,
        ]);
    }
}
