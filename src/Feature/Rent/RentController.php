<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;

final class RentController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RentStore $rent,
        private readonly RentBook $book,
        private readonly RegisterStore $registers,
        private readonly ActiveRegister $drawers,
        private readonly MallPayoutSettings $settings,
        private readonly RentReceipt $receipts,
        private readonly JournalCash $cash,
        private readonly LateFee $lateFees,
    ) {
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
        if ($this->settings->rentPrepaid()) {
            $this->render($slug, 'this rent comes out of sales');

            return;
        }
        $boothId = $this->app->request()->field('booth_id');
        $postingDate = $this->app->request()->field('posting_date');
        try {
            $this->rent->postCharge($boothId, $postingDate);
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        }
        $this->saved($slug, $postingDate, 'Rent charged.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/collect', ['GET'])]
    public function collectForm(string $slug): void
    {
        $this->showCollect($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/payment', ['POST'])]
    public function payment(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->app->request()->field('vendor_id');
        $boothId = $this->app->request()->field('booth_id');
        $today = $this->rent->today();
        try {
            $journalId = $this->receipts->collect(
                $vendorId,
                $boothId,
                $today,
                $this->registers->openSession($this->drawers->posted($slug, $this->app->request()->field('register_session_id'), true)),
                $this->app->request()->field('amount'),
                [
                    'cash' => $this->app->request()->field('tender_cash'),
                    'card' => $this->app->request()->field('tender_card'),
                    'checks' => $this->app->request()->field('tender_checks'),
                    'tendered' => $this->app->request()->field('cash_tendered'),
                    'check' => $this->app->request()->field('check_number'),
                ],
            );
        } catch (DatabaseRefusal $refusal) {
            $this->showCollect($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->showCollect($slug, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/rent/receipt/' . $journalId);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/late-fee', ['POST'])]
    public function lateFee(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $journalId = $this->lateFees->charge(
                $this->app->request()->field('booth_id'),
                $this->app->request()->field('posting_date'),
                $this->app->request()->field('amount'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Late fee posted. Void that entry to reverse it.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/receipt/([0-9a-fA-F-]{36})', ['GET'])]
    public function slip(string $slug, string $journalId): void
    {
        $this->app->open($slug, true);
        $slip = $this->receipts->read($journalId);
        if ($slip === null) {
            $this->render($slug, 'that rent receipt is missing');
            return;
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Rent/rent-slip', [
            'title' => 'Rent receipt',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'slip' => $slip,
            'change' => $this->receipts->change($this->cash->tendered($journalId), $slip['cash']),
        ]);
    }

    private function showCollect(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $vendorId = $this->chosen('vendor');
        if ($vendorId === '') {
            $vendorId = $this->chosen('vendor_id');
        }
        $boothId = $this->chosen('booth');
        if ($boothId === '') {
            $boothId = $this->chosen('booth_id');
        }
        $figure = $this->receipts->figure($vendorId, $boothId, $this->rent->today());
        if ($figure === null) {
            $this->render($slug, $error ?? 'a rent receipt cannot exceed the open rent');
            return;
        }
        $this->app->show('Feature/Rent/collect', [
            'title' => 'Collect rent',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'figure' => $figure,
            'amount' => $this->kept('amount', $figure['collect']),
            'tenderCash' => $this->kept('tender_cash', $figure['collect']),
            'tenderCard' => $this->kept('tender_card', ''),
            'tenderChecks' => $this->kept('tender_checks', ''),
            'cashTendered' => $this->kept('cash_tendered', $figure['collect']),
            'checkNumber' => $this->kept('check_number', ''),
            'error' => $error,
            'openRegister' => $this->registers->openSessions() !== [],
        ]);
    }

    private function kept(string $name, string $fallback): string
    {
        $posted = $this->app->request()->field($name);
        if ($posted !== '') {
            return $posted;
        }

        return $fallback;
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->rent->today();
        $month = $this->book->month($this->chosen('month'), $today);
        $fromSales = $this->settings->rentPrepaid();
        $book = $this->book->assemble($this->rent->facts($month), $month, $today);
        $visible = $this->book->narrow($book['rows'], $this->chosen('q'), $fromSales ? '' : $this->chosen('standing'));
        $this->app->show('Feature/Rent/rent', [
            'title' => 'Rent',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'month' => $book['month'],
            'label' => $book['label'],
            'previous' => $book['previous'],
            'next' => $book['next'],
            'today' => $book['today'],
            'stats' => $book['stats'],
            'rows' => $visible['rows'],
            'total' => count($book['rows']),
            'counts' => $visible['counts'],
            'matched' => $visible['matched'],
            'query' => $this->chosen('q'),
            'standing' => $visible['standing'],
            'fromSales' => $fromSales,
            'debtors' => $fromSales ? $this->receipts->debtors($today) : [],
            'backlog' => $fromSales ? [] : $this->rent->uncharged($today, $month),
            'error' => $error,
        ]);
    }

    private function chosen(string $name): string
    {
        $query = $this->app->request()->query($name);
        if ($query !== '') {
            return $query;
        }

        return $this->app->request()->field($name);
    }

    private function saved(string $slug, string $postingDate, string $notice): never
    {
        $month = $this->chosen('month');
        if ($month === '') {
            $month = substr($postingDate, 0, 7);
        }
        $month = $this->book->month($month, $this->rent->today());
        $this->app->redirect('/' . $slug . '/rent?month=' . $month, $notice);
    }
}
