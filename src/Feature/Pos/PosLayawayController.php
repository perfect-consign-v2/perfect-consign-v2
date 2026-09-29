<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class PosLayawayController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly LayawayBook $layaways,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/layaway', ['POST'])]
    public function deposit(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $cart = $this->tickets->cart($session);
            $request = $this->app->request();
            $check = trim($request->field('check_number'));
            $this->layaways->deposit(
                $session,
                $this->mall->today(),
                $cart['ticket_id'],
                Money::parseMinor($request->field('layaway_amount')),
                $cart['due'],
                $request->field('layaway_tender'),
                $check === '' ? null : $check,
            );
        }, 'Layaway deposit saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/layaway/cancel', ['POST'])]
    public function cancel(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->layaways->cancel($session, $this->mall->today(), $this->ticketId());
        }, 'Layaway cancelled.');
    }

    /**
     * @param callable(string): void $work
     */
    private function act(string $slug, callable $work, string $notice): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $this->sales->refuse($slug);
            $work($this->gate->session($slug));
        } catch (DatabaseRefusal $refusal) {
            $this->page->cart($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->cart($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/pos', $notice);
    }

    private function ticketId(): string
    {
        $id = $this->app->request()->field('ticket_id');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new DatabaseRefusal('that ticket is not parked');
        }

        return $id;
    }
}
