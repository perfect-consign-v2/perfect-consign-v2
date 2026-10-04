<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Ledger\PostedLines;

final class RentController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly RentStore $rent,
        private readonly RentBook $book,
        private readonly RegisterStore $registers,
        private readonly ActiveRegister $drawers,
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
        $boothId = $this->app->request()->field('booth_id');
        $postingDate = $this->app->request()->field('posting_date');
        try {
            $amount = $this->rent->chargeAmount($boothId, $postingDate);
            $month = substr($postingDate, 0, 7);
            $this->ledger->post(new JournalDraft(
                postingKey: 'rent-charge:' . $boothId . ':' . $month,
                postingDate: $postingDate,
                source: new JournalSource('rent_charge', $boothId . ':' . $month, 'Rent charge'),
                lines: [
                    PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), $amount, 0, 'booth', $boothId),
                    PostedLines::entry($this->ledger->roleAccount('rent_revenue'), 0, $amount, null, null),
                ],
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        }
        $this->saved($slug, $postingDate, 'Rent charged.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rent/payment', ['POST'])]
    public function payment(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $reference = $this->app->request()->field('source_reference');
        $boothId = $this->app->request()->field('booth_id');
        $postingDate = $this->app->request()->field('posting_date');
        try {
            if ($reference === '') {
                throw new DatabaseRefusal('a source reference is required');
            }
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $this->ledger->post(new JournalDraft(
                postingKey: 'rent-payment:' . $reference,
                postingDate: $postingDate,
                source: new JournalSource('rent_payment', $reference, 'Rent payment'),
                lines: [
                    PostedLines::entry($this->ledger->roleAccount('cash'), $amount, 0, null, null),
                    PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), 0, $amount, 'booth', $boothId),
                ],
                sessionId: $this->registers->openSession($this->drawers->posted($slug, $this->app->request()->field('register_session_id'), true)),
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());
            return;
        }
        $this->saved($slug, $postingDate, 'Rent payment posted.');
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->rent->today();
        $month = $this->book->month($this->chosen('month'), $today);
        $book = $this->book->assemble($this->rent->facts($month), $month, $today);
        $visible = $this->book->narrow($book['rows'], $this->chosen('q'), $this->chosen('standing'));
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
