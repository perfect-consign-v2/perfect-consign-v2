<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Staff\StaffStore;

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
        private readonly StaffStore $staff,
        private readonly VendorYearPaid $paid,
        private readonly VendorClawback $clawback,
    ) {
    }

    public function show(string $slug, string $id, ?string $error): void
    {
        $today = $this->mall->today();
        $assembled = $this->roster->assemble($this->parties->vendorRows($today), $today);
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
        $vendor['clawback_minor'] = $this->clawback->openReceivable($id);
        $vendor['year_paid_minor'] = $this->paid->yearPaid($id);
        $histories = [];
        foreach (array_merge($vendor['current'], $vendor['upcoming'], $vendor['past']) as $stay) {
            $histories[$stay['id']] = $this->parties->rentHistory($stay['id']);
        }
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
}
