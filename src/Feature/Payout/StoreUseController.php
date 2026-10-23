<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use InvalidArgumentException;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class StoreUseController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly StoreUse $uses,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/store-use', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Payout/store-use', $this->page($tenant->displayName, $tenant->slug, null));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/store-use', ['POST'])]
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
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $journalId = $this->uses->record(
                $vendorId,
                $itemCode,
                $amount,
                $date,
                $description,
                $memo,
                $this->app->request()->field('form_token'),
            );
            $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Store use posted.');
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('Feature/Payout/store-use', $this->page($tenant->displayName, $tenant->slug, $this->app->catchRefusal($refusal)));
        } catch (InvalidArgumentException $error) {
            $this->app->show('Feature/Payout/store-use', $this->page($tenant->displayName, $tenant->slug, $error->getMessage()));
        }
    }

    /**
     * @return array{title: string, displayName: string, slug: string, vendors: list<array{id: string, name: string}>, error: ?string, vendorId: string, itemCode: string, description: string, amount: string, memo: string, postingDate: string, formToken: string}
     */
    private function page(string $displayName, string $slug, ?string $error): array
    {
        return [
            'title' => 'Buy for the store',
            'displayName' => $displayName,
            'slug' => $slug,
            'vendors' => $this->uses->vendors(),
            'error' => $error,
            'vendorId' => $this->app->request()->field('vendor_id'),
            'itemCode' => $this->app->request()->field('item_code'),
            'description' => $this->app->request()->field('description'),
            'amount' => $this->app->request()->field('amount'),
            'memo' => $this->app->request()->field('memo'),
            'postingDate' => $this->app->request()->field('posting_date'),
            'formToken' => $this->formToken(),
        ];
    }

    private function formToken(): string
    {
        $token = $this->app->request()->field('form_token');
        if (preg_match('/^[0-9a-f]{32}$/', $token) === 1) {
            return $token;
        }

        return bin2hex(random_bytes(16));
    }
}
