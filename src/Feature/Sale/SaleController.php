<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Sale;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Register\RegisterStore;
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
        private readonly RegisterStore $registers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/sale', ['GET'])]
    public function form(string $slug): void
    {
        $this->app->open($slug, true);
        $this->requireRegister($slug);
        $this->render($slug, $this->ledger->newId(), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/sale', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $this->requireRegister($slug);
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
            $split = $this->tenders();
            if ($split['sum'] !== $amount) {
                throw new DatabaseRefusal('the tenders must add up to the sale');
            }
            $vendorId = $this->app->request()->field('vendor_id');
            $lines = $split['lines'];
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
                $lines,
                null,
                $this->registers->openSession($this->app->postedRegister($slug)),
                $split['check']
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

    /**
     * @return array{lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>, sum: int, check: ?string}
     */
    private function tenders(): array
    {
        $kinds = [
            'gift_certificate' => 'gift_certificate',
            'gift_card' => 'gift_card',
            'store_credit' => 'store_credit',
        ];
        $lines = [];
        $sum = 0;
        $check = null;
        foreach (['cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit'] as $role) {
            $minor = $this->optionalMinor('tender_' . $role);
            if ($minor === 0) {
                continue;
            }
            $type = null;
            $ref = null;
            if (isset($kinds[$role])) {
                $type = $kinds[$role];
                $ref = $this->registers->storedValueId($role, $this->app->request()->field($role . '_number'));
            }
            if ($role === 'checks') {
                $number = trim($this->app->request()->field('check_number'));
                if ($number === '' || strlen($number) > 40) {
                    throw new DatabaseRefusal('a check sale needs a check number');
                }
                $check = $number;
            }
            $lines[] = PostedLines::entry($this->ledger->roleAccount($role), $minor, 0, $type, $ref);
            $sum += $minor;
        }
        if ($lines === []) {
            throw new DatabaseRefusal('a sale debits each tender once');
        }

        return ['lines' => $lines, 'sum' => $sum, 'check' => $check];
    }

    private function optionalMinor(string $field): int
    {
        $value = $this->app->request()->field($field);
        if ($value === '' || $value === '0' || $value === '0.00') {
            return 0;
        }

        return Money::parseMinor($value);
    }

    private function requireRegister(string $slug): void
    {
        if ($this->app->drawerSession($slug) === '') {
            $this->app->redirect(
                '/' . $slug . '/registers',
                'Open a register and make it active before the sale screen.'
            );
        }
    }

    private function render(string $slug, string $saleId, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Sale/sale', [
            'title' => 'Sale',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $this->parties->vendors(),
            'registers' => $this->registers->openSessions(),
            'saleId' => $saleId,
            'error' => $error,
        ]);
    }
}
