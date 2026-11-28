<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\Csv;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Tenant\TenantRecord;

/**
 * Staff reports for vendor money: the vendor register (a bank book for one
 * vendor) and rent payments (every way rent was paid, store wide). Both are
 * built by the database; this page shows them, prints them, and downloads them.
 *
 * @phpstan-import-type RegisterRow from VendorMoney
 * @phpstan-import-type PaymentRow from VendorMoney
 */
final class VendorMoneyController
{
    private readonly ReportAccess $access;

    public function __construct(
        private readonly MallApp $app,
        Actor $actor,
        private readonly MallSettings $mall,
        private readonly VendorMoney $money,
    ) {
        $this->access = new ReportAccess($app, $actor);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/vendor-register', ['GET'])]
    public function register(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $filters = VendorMoneyFilters::read($this->app->request(), $this->mall->today(), ['vendor', 'booth']);
        [$rows, $error] = $this->registerRows($filters);
        $this->show($tenant, 'Feature/Report/vendor-register', 'Vendor register', $filters, $error, [
            'rows' => $rows,
            'vendors' => $this->money->vendors(),
            'booths' => $this->money->booths(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/vendor-register.csv', ['GET'])]
    public function registerCsv(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $filters = VendorMoneyFilters::read($this->app->request(), $this->mall->today(), ['vendor', 'booth']);
        [$rows, $error] = $this->registerRows($filters);
        if ($error !== null) {
            $this->show($tenant, 'Feature/Report/vendor-register', 'Vendor register', $filters, $error, [
                'rows' => [],
                'vendors' => $this->money->vendors(),
                'booths' => $this->money->booths(),
            ]);

            return;
        }
        $body = [];
        foreach ($rows as $row) {
            $body[] = [
                'Date' => $row['date'],
                'What happened' => $row['what'],
                'Booth' => $row['booth'],
                'Debit' => $row['debit'] === null ? '' : Money::decimal($row['debit']),
                'Credit' => $row['credit'] === null ? '' : Money::decimal($row['credit']),
                'Balance' => Money::decimal($row['balance']),
            ];
        }
        $this->download('vendor-register.csv', ['Date', 'What happened', 'Booth', 'Debit', 'Credit', 'Balance'], $body);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent-payments', ['GET'])]
    public function payments(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $filters = VendorMoneyFilters::read($this->app->request(), $this->mall->today(), ['vendor', 'booth', 'paid_by', 'q']);
        [$rows, $totals, $error] = $this->paymentRows($filters);
        $this->show($tenant, 'Feature/Report/rent-payments', 'Rent payments', $filters, $error, [
            'rows' => $rows,
            'totals' => $totals,
            'vendors' => $this->money->vendors(),
            'booths' => $this->money->booths(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent-payments.csv', ['GET'])]
    public function paymentsCsv(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $filters = VendorMoneyFilters::read($this->app->request(), $this->mall->today(), ['vendor', 'booth', 'paid_by', 'q']);
        [$rows, $totals, $error] = $this->paymentRows($filters);
        if ($error !== null) {
            $this->show($tenant, 'Feature/Report/rent-payments', 'Rent payments', $filters, $error, [
                'rows' => [],
                'totals' => $totals,
                'vendors' => $this->money->vendors(),
                'booths' => $this->money->booths(),
            ]);

            return;
        }
        $body = [];
        foreach ($rows as $row) {
            $body[] = [
                'Date' => $row['date'],
                'Receipt' => $row['number'],
                'Vendor' => $row['vendor'],
                'Booth' => $row['booth'],
                'Months' => $row['months'],
                'How paid' => $row['how'] . ($row['voided'] ? ' (voided)' : ''),
                'Register' => $row['register'] !== '' ? $row['register'] : ($row['run_date'] !== '' ? 'Payout of ' . $row['run_date'] : ''),
                'Cashier' => $row['cashier'],
                'Amount' => Money::decimal($row['amount']),
            ];
        }
        $this->download(
            'rent-payments.csv',
            ['Date', 'Receipt', 'Vendor', 'Booth', 'Months', 'How paid', 'Register', 'Cashier', 'Amount'],
            $body,
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/bad-debts', ['GET'])]
    public function badDebts(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $filters = VendorMoneyFilters::read($this->app->request(), $this->mall->today(), []);
        $this->show($tenant, 'Feature/Report/bad-debts', 'Bad debts', $filters, $filters->error, [
            'rows' => $filters->error === null ? $this->money->badDebts($filters->window->from, $filters->window->to) : [],
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/bad-debts.csv', ['GET'])]
    public function badDebtsCsv(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $filters = VendorMoneyFilters::read($this->app->request(), $this->mall->today(), []);
        if ($filters->error !== null) {
            $this->show($tenant, 'Feature/Report/bad-debts', 'Bad debts', $filters, $filters->error, ['rows' => []]);

            return;
        }
        $body = [];
        foreach ($this->money->badDebts($filters->window->from, $filters->window->to) as $row) {
            $body[] = [
                'Date' => $row['date'],
                'Vendor' => $row['vendor'],
                'Booth' => $row['booth'],
                'What' => $row['kind'] === 'rent' ? 'Rent' : 'Returns',
                'Amount' => Money::decimal($row['amount']),
                'Taken back' => Money::decimal($row['recovered']),
                'Reason' => $row['reason'],
                'Who' => $row['who'],
            ];
        }
        $this->download('bad-debts.csv', ['Date', 'Vendor', 'Booth', 'What', 'Amount', 'Taken back', 'Reason', 'Who'], $body);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/unclaimed', ['GET'])]
    public function unclaimed(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $this->app->show('Feature/Report/unclaimed', [
            'title' => 'Unclaimed money',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'today' => $this->mall->today(),
            'rows' => $this->money->unclaimed($this->mall->today()),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/unclaimed.csv', ['GET'])]
    public function unclaimedCsv(string $slug): void
    {
        $this->access->open($slug);
        $body = [];
        foreach ($this->money->unclaimed($this->mall->today()) as $row) {
            $body[] = [
                'Vendor' => $row['vendor'],
                'Code' => $row['code'],
                'Moved on' => $row['moved_on'],
                'Days held' => (string) $row['days'],
                'Moved' => Money::decimal($row['moved']),
                'Paid to vendor' => Money::decimal($row['paid']),
                'Sent to the state' => Money::decimal($row['remitted']),
                'Still held' => Money::decimal($row['held']),
                'State report numbers' => $row['references'],
            ];
        }
        $this->download(
            'unclaimed-money.csv',
            ['Vendor', 'Code', 'Moved on', 'Days held', 'Moved', 'Paid to vendor', 'Sent to the state', 'Still held', 'State report numbers'],
            $body,
        );
    }

    /**
     * @return array{0: list<RegisterRow>, 1: ?string}
     */
    private function registerRows(VendorMoneyFilters $filters): array
    {
        if ($filters->error !== null) {
            return [[], $filters->error];
        }
        if ($filters->chosen['vendor'] === '') {
            return [[], null];
        }
        try {
            return [$this->money->register(
                $filters->chosen['vendor'],
                $filters->window->from,
                $filters->window->to,
                $filters->chosen['booth'],
            ), null];
        } catch (DatabaseRefusal $refusal) {
            return [[], $this->app->catchRefusal($refusal)];
        }
    }

    /**
     * @return array{0: list<PaymentRow>, 1: list<array{kind: string, label: string, count: int, amount: int}>, 2: ?string}
     */
    private function paymentRows(VendorMoneyFilters $filters): array
    {
        if ($filters->error !== null) {
            return [[], [], $filters->error];
        }
        try {
            $from = $filters->window->from;
            $to = $filters->window->to;

            return [
                $this->money->payments($from, $to, $filters->chosen),
                $this->money->paymentTotals($from, $to, $filters->chosen),
                null,
            ];
        } catch (DatabaseRefusal $refusal) {
            return [[], [], $this->app->catchRefusal($refusal)];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function show(TenantRecord $tenant, string $template, string $title, VendorMoneyFilters $filters, ?string $error, array $data): void
    {
        $this->app->show($template, $data + [
            'title' => $title,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'label' => $filters->window->label,
            'from' => $filters->window->from,
            'to' => $filters->window->to,
            'chosen' => $filters->chosen,
            'thisMonth' => $filters->query('this_month'),
            'lastMonth' => $filters->query('last_month'),
            'download' => $filters->query(),
            'error' => $error,
        ]);
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, string>> $body
     */
    private function download(string $filename, array $headers, array $body): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo Csv::document($headers, $body);
        throw new ResponseSent('csv');
    }
}
