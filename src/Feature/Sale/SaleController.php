<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Sale;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Ledger\PostedLines;

final class SaleController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly PartyStore $parties,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/sale', ['GET'])]
    public function form(string $slug): void
    {
        $this->app->open($slug, true);
        $this->render($slug, $this->ledger->newId(), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/sale', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $saleId = $this->app->request()->field('sale_id');
        try {
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $saleId) !== 1) {
                throw new DatabaseRefusal('a sale id is required');
            }
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $commission = $this->optionalMinor('commission');
            $tax = $this->optionalMinor('tax');
            $payable = $amount - $commission - $tax;
            if ($payable < 1) {
                throw new DatabaseRefusal('the vendor payable is the amount left after commission and tax');
            }
            $tender = $this->app->request()->field('tender');
            if ($tender !== 'cash' && $tender !== 'card') {
                throw new DatabaseRefusal('a sale debits cash or card once');
            }
            $vendorId = $this->app->request()->field('vendor_id');
            $lines = [
                PostedLines::entry($this->ledger->roleAccount($tender), $amount, 0, null, null),
            ];
            if ($commission > 0) {
                $lines[] = PostedLines::entry($this->ledger->roleAccount('commission_revenue'), 0, $commission, null, null);
            }
            if ($tax > 0) {
                $lines[] = PostedLines::entry($this->ledger->roleAccount('sales_tax_payable'), 0, $tax, null, null);
            }
            $lines[] = PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $payable, 'vendor', $vendorId);
            $journalId = $this->ledger->post(
                $saleId,
                $this->app->request()->field('posting_date'),
                'sale',
                $saleId,
                'Sale',
                $lines
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $saleId, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $saleId, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $tenant->slug . '/journals/' . $journalId, 'Sale posted.');
    }

    private function optionalMinor(string $field): int
    {
        $value = $this->app->request()->field($field);
        if ($value === '' || $value === '0' || $value === '0.00') {
            return 0;
        }

        return Money::parseMinor($value);
    }

    private function render(string $slug, string $saleId, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Sale/sale', [
            'title' => 'Sale',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $this->parties->vendors(),
            'saleId' => $saleId,
            'error' => $error,
        ]);
    }
}
