<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class PosTenderController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly TicketTenders $held,
        private readonly GiftIssue $gifts,
        private readonly LayawayBook $layaways,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/tender', ['GET'])]
    public function open(string $slug): void
    {
        $this->app->open($slug, true);
        $this->app->redirect('/' . $slug . '/pos');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/tender', ['POST'])]
    public function apply(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $cart = $this->tickets->cart($session);
            $request = $this->app->request();
            $role = $request->field('tender_role');
            $reference = $request->field('tender_reference');
            if ($role === 'vendor_draw') {
                $reference = $request->field('vendor_id');
            }
            $this->held->apply(
                $cart['ticket_id'],
                $role,
                Money::parseMinor($request->field('tender_amount')),
                $reference,
                $cart['due'] - $this->layaways->balance($cart['ticket_id']),
            );
        }, 'Tender saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/tender/remove', ['POST'])]
    public function remove(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $cart = $this->tickets->cart($session);
            $this->held->remove($cart['ticket_id'], $this->app->request()->field('tender_role'));
        }, 'Tender removed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/gift', ['POST'])]
    public function gift(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $request = $this->app->request();
            $check = trim($request->field('check_number'));
            $this->gifts->issue(
                $session,
                $this->mall->today(),
                $request->field('gift_number'),
                Money::parseMinor($request->field('gift_amount')),
                $request->field('gift_tender'),
                $check === '' ? null : $check,
            );
        }, 'Gift certificate sold.');
    }

    /**
     * @param callable(string): void $work
     */
    private function act(string $slug, callable $work, string $notice): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $this->sales->refuse($slug);
            $work($session);
        } catch (DatabaseRefusal $refusal) {
            $this->page->cart($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->cart($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/pos', $notice);
    }
}
