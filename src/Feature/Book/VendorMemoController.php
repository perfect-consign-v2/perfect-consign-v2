<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use InvalidArgumentException;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;

final class VendorMemoController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly VendorMemo $memos,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor-memo', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Book/vendor-memo', $this->page($tenant->displayName, $tenant->slug, null, null));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor-memo', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $vendorId = $this->app->request()->field('vendor_id');
        $direction = $this->app->request()->field('direction');
        $reason = $this->app->request()->field('reason');
        $date = $this->app->request()->field('posting_date');
        try {
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $owed = $this->memos->openPayable($vendorId);
            if ($this->app->request()->field('post') !== '1') {
                $this->app->show('Feature/Book/vendor-memo', $this->page(
                    $tenant->displayName,
                    $tenant->slug,
                    null,
                    $owed,
                ));
                return;
            }
            $journalId = $this->memos->record($vendorId, $direction, $amount, $date, $reason);
            $this->app->redirect('/' . $slug . '/journals/' . $journalId, $this->mall->getPartyLabel('Vendor memo posted.', 'Consignor memo posted.'));
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('Feature/Book/vendor-memo', $this->page(
                $tenant->displayName,
                $tenant->slug,
                $this->app->catchRefusal($refusal),
                null,
            ));
            return;
        } catch (InvalidArgumentException $error) {
            $this->app->show('Feature/Book/vendor-memo', $this->page(
                $tenant->displayName,
                $tenant->slug,
                $error->getMessage(),
                null,
            ));
            return;
        }
    }

    /**
     * @return array{title: string, displayName: string, slug: string, vendors: list<array{id: string, name: string}>, error: ?string, owed: ?string, vendorId: string, direction: string, amount: string, reason: string, postingDate: string}
     */
    private function page(string $displayName, string $slug, ?string $error, ?int $owed): array
    {
        return [
            'title' => $this->mall->getPartyLabel('Vendor memo', 'Consignor memo'),
            'displayName' => $displayName,
            'slug' => $slug,
            'vendors' => $this->memos->vendors(),
            'error' => $error,
            'owed' => $owed === null ? null : View::money($owed),
            'vendorId' => $this->app->request()->field('vendor_id'),
            'direction' => $this->app->request()->field('direction'),
            'amount' => $this->app->request()->field('amount'),
            'reason' => $this->app->request()->field('reason'),
            'postingDate' => $this->app->request()->field('posting_date'),
        ];
    }
}
