<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use InvalidArgumentException;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;

final class MissedSaleController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MissedSale $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/missed-sale', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Book/missed-sale', $this->page($tenant->displayName, $tenant->slug, null, null));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/missed-sale', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $vendorId = $this->app->request()->field('vendor_id');
        $itemCode = $this->app->request()->field('item_code');
        $description = $this->app->request()->field('description');
        $memo = $this->app->request()->field('memo');
        $date = $this->app->request()->field('posting_date');
        try {
            $price = Money::parseMinor($this->app->request()->field('price'));
            $share = $this->sales->vendorShare($vendorId, $itemCode, $price);
            if ($this->app->request()->field('post') !== '1') {
                $this->app->show('Feature/Book/missed-sale', $this->page(
                    $tenant->displayName,
                    $tenant->slug,
                    null,
                    $share,
                ));
                return;
            }
            $journalId = $this->sales->record($vendorId, $itemCode, $price, $date, $description, $memo);
            $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Missed sale posted.');
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('Feature/Book/missed-sale', $this->page(
                $tenant->displayName,
                $tenant->slug,
                $this->app->catchRefusal($refusal),
                null,
            ));
            return;
        } catch (InvalidArgumentException $error) {
            $this->app->show('Feature/Book/missed-sale', $this->page(
                $tenant->displayName,
                $tenant->slug,
                $error->getMessage(),
                null,
            ));
            return;
        }
    }

    /**
     * @return array{title: string, displayName: string, slug: string, vendors: list<array{id: string, name: string}>, error: ?string, share: ?string, vendorId: string, itemCode: string, description: string, price: string, memo: string, postingDate: string}
     */
    private function page(string $displayName, string $slug, ?string $error, ?int $share): array
    {
        return [
            'title' => 'Missed sale',
            'displayName' => $displayName,
            'slug' => $slug,
            'vendors' => $this->sales->vendors(),
            'error' => $error,
            'share' => $share === null ? null : View::money($share),
            'vendorId' => $this->app->request()->field('vendor_id'),
            'itemCode' => $this->app->request()->field('item_code'),
            'description' => $this->app->request()->field('description'),
            'price' => $this->app->request()->field('price'),
            'memo' => $this->app->request()->field('memo'),
            'postingDate' => $this->app->request()->field('posting_date'),
        ];
    }
}
