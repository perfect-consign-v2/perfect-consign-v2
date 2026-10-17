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
        private readonly LateFeeDue $lateDue,
    ) {
        $this->forms = new RentForm();
    }

    private RentForm $forms;

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
        $waived = $this->forms->checked($this->app->request()->post['waive'] ?? []);
        $cash = $this->app->request()->field('tender_cash');
        $card = $this->app->request()->field('tender_card');
        $checks = $this->app->request()->field('tender_checks');
        $amount = $this->app->request()->field('amount');
        $session = '';
        if ($this->forms->needsDrawer($waived, $cash, $card, $checks, $amount)) {
            $session = $this->registers->openSession($this->drawers->posted($slug, $this->app->request()->field('register_session_id'), true));
        }
        try {
            $journalId = $this->receipts->collect(
                $vendorId,
                $boothId,
                $today,
                $session,
                $amount,
                [
                    'way' => $this->app->request()->field('pay_with'),
                    'cash' => $cash,
                    'card' => $card,
                    'checks' => $checks,
                    'tendered' => $this->app->request()->field('cash_tendered'),
                    'check' => $this->app->request()->field('check_number'),
                ],
                $waived,
                $this->app->request()->field('waive_note'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->showCollect($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->showCollect($slug, $error->getMessage());
            return;
        }
        if ($journalId === '') {
            $notice = count($waived) === 1 ? 'Late fee waived.' : 'Late fees waived.';
            $this->app->redirect('/' . $slug . '/rent', $notice);
        }
        $this->app->redirect('/' . $slug . '/rent/receipt/' . $journalId);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/waive', ['POST'])]
    public function waive(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->chosen('vendor_id');
        $boothId = $this->chosen('booth_id');
        $today = $this->rent->today();
        $figure = $this->receipts->figure($vendorId, $boothId, $today);
        if ($figure === null) {
            $this->render($slug, 'Choose a late fee.');

            return;
        }
        try {
            $count = $this->waiveChosen($figure['vendor_id'], $figure['owed'], $today);
        } catch (DatabaseRefusal $refusal) {
            $this->showCollect($slug, $this->app->catchRefusal($refusal));

            return;
        }
        if ($count < 1) {
            $this->showCollect($slug, 'Choose a late fee.');

            return;
        }
        $notice = $count === 1 ? 'Late fee waived.' : 'Late fees waived.';
        $still = $this->receipts->figure($figure['vendor_id'], $figure['booth_id'], $today);
        if ($still === null) {
            $this->app->redirect('/' . $slug . '/rent', $notice);
        }
        $this->app->redirect(
            '/' . $slug . '/rent/collect?vendor=' . rawurlencode($figure['vendor_id']) . '&booth=' . rawurlencode($figure['booth_id']),
            $notice,
        );
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
            'companyLines' => $this->app->getCompanyLines(),
            'receiptFooter' => $this->app->getReceiptFooter(),
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
        $today = $this->rent->today();
        $figure = $this->receipts->figure($vendorId, $boothId, $today);
        if ($figure === null) {
            $this->render($slug, $error ?? 'a rent receipt cannot exceed the open rent');
            return;
        }
        $returning = $this->app->request()->field('pay_with') !== '';
        $this->app->show('Feature/Rent/collect', [
            'title' => 'Collect rent',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'figure' => $figure,
            'lines' => $this->receipts->explain($figure['vendor_id'], $figure['owed'], $today),
            'tenderCash' => $returning ? $this->app->request()->field('tender_cash') : '',
            'tenderCard' => $returning ? $this->app->request()->field('tender_card') : '',
            'tenderChecks' => $returning ? $this->app->request()->field('tender_checks') : '',
            'checkNumber' => $returning ? $this->app->request()->field('check_number') : '',
            'error' => $error,
            'openRegister' => $this->registers->openSessions() !== [],
        ]);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->rent->today();
        try {
            $this->lateDue->apply($today);
        } catch (DatabaseRefusal $refusal) {
            $error = $error ?? $this->app->catchRefusal($refusal);
        }
        $month = $this->book->month($this->chosen('month'), $today);
        $fromSales = $this->settings->rentPrepaid();
        $book = $this->book->assemble($this->rent->facts($month), $month, $today, $this->settings->cycleDay());
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

    private function waiveChosen(string $vendorId, int $owed, string $today): int
    {
        $open = [];
        foreach ($this->receipts->explain($vendorId, $owed, $today) as $line) {
            if ($line['journal_id'] !== '') {
                $open[$line['journal_id']] = true;
            }
        }
        $chosen = [];
        if ($this->app->request()->field('all') === '1') {
            foreach (array_keys($open) as $journalId) {
                if (is_string($journalId)) {
                    $chosen[] = $journalId;
                }
            }
        } elseif (isset($open[$this->app->request()->field('journal_id')])) {
            $chosen[] = $this->app->request()->field('journal_id');
        }
        foreach ($chosen as $journalId) {
            $this->lateDue->waive($journalId, $today);
        }

        return count($chosen);
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
