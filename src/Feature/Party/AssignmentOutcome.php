<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;

/**
 * Where a booth action lands: back on the vendor's page with the notice, or,
 * when it was refused, the vendor's page again with the refusal (or the
 * vendor list when no vendor was named).
 */
final class AssignmentOutcome
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly AssignmentBook $stays,
        private readonly VendorPage $pages,
        private readonly MallSettings $mall,
        private readonly VendorRoster $roster,
        private readonly SellerPaths $sellerPaths,
    ) {
    }

    /**
     * @param array<string, mixed>|null $booking
     */
    public function finish(string $slug, string $vendorId, string $notice, ?string $error, ?array $booking = null): void
    {
        if ($error === null && $booking === null) {
            $this->app->redirect($this->vendorPath($slug, $vendorId), $notice);
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) === 1 && $this->parties->findVendor($vendorId) !== null) {
            $this->pages->show($slug, $vendorId, $error, $booking);

            return;
        }
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $assembled = $this->roster->assemble($this->stays->vendorRows($today), $today);
        $query = $this->app->request()->query('q');
        $status = $this->roster->selected($this->app->request()->query('status'));
        $this->app->show('Feature/Party/parties', [
            'title' => $this->mall->getPartyLabel('Vendors', 'Consignors'),
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'stats' => $assembled['stats'],
            'vendors' => $this->roster->matching($assembled['vendors'], $status, $query),
            'status' => $status,
            'query' => $query,
            'error' => $error ?? 'Choose a vendor.',
            'sellerPrefix' => $this->sellerPaths->prefix(),
        ]);
    }

    public function vendorOf(string $assignmentId): string
    {
        foreach ($this->stays->assignments() as $assignment) {
            if (($assignment['id'] ?? null) !== $assignmentId) {
                continue;
            }
            $vendorId = $assignment['vendor_id'] ?? null;
            if (is_string($vendorId)) {
                return $vendorId;
            }
        }

        return '';
    }

    private function vendorPath(string $slug, string $vendorId): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return $this->sellerPaths->list($slug);
        }

        return $this->sellerPaths->record($slug, $vendorId);
    }
}
