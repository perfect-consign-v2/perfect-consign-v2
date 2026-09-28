<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;
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
        private readonly PartyStore $parties,
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
            $amount = $this->rent->rate($boothId, $postingDate);
            if ($amount === null) {
                throw new DatabaseRefusal('a rent charge requires the rate stored for that month');
            }
            $month = substr($postingDate, 0, 7);
            $journalId = $this->ledger->post(new JournalDraft(
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
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Rent charged.');
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
            $journalId = $this->ledger->post(new JournalDraft(
                postingKey: 'rent-payment:' . $reference,
                postingDate: $this->app->request()->field('posting_date'),
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
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Rent payment posted.');
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Rent/rent', [
            'title' => 'Rent',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booths' => $this->parties->booths(),
            'error' => $error,
        ]);
    }
}
