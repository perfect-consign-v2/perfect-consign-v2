<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\MallApp;

final class RentController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/charge', ['POST'])]
    public function charge(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $boothId = $this->app->request()->field('booth_id');
        $postingDate = $this->app->request()->field('posting_date');
        try {
            $amount = $this->app->books()->rate($boothId, $postingDate);
            if ($amount === null) {
                throw new DatabaseRefusal('a rent charge requires the rate stored for that month');
            }
            $month = substr($postingDate, 0, 7);
            $journalId = $this->app->books()->post(
                'rent-charge:' . $boothId . ':' . $month,
                $postingDate,
                'rent_charge',
                $boothId . ':' . $month,
                'Rent charge',
                [
                    $this->line($this->app->books()->roleAccount('booth_rent_receivable'), $amount, 0, 'booth', $boothId),
                    $this->line($this->app->books()->roleAccount('rent_revenue'), 0, $amount, null, null),
                ]
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/payment', ['POST'])]
    public function payment(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $reference = $this->app->request()->field('source_reference');
        $boothId = $this->app->request()->field('booth_id');
        try {
            if ($reference === '') {
                throw new DatabaseRefusal('a source reference is required');
            }
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $journalId = $this->app->books()->post(
                'rent-payment:' . $reference,
                $this->app->request()->field('posting_date'),
                'rent_payment',
                $reference,
                'Rent payment',
                [
                    $this->line($this->app->books()->roleAccount('cash'), $amount, 0, null, null),
                    $this->line($this->app->books()->roleAccount('booth_rent_receivable'), 0, $amount, 'booth', $boothId),
                ]
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('rent', [
            'title' => 'Rent',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booths' => $this->app->books()->booths(),
            'error' => $error,
        ]);
    }

    /**
     * @return array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}
     */
    private function line(string $accountId, int $debit, int $credit, ?string $type, ?string $ref): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'subledger_type' => $type,
            'subledger_ref' => $ref,
        ];
    }
}
