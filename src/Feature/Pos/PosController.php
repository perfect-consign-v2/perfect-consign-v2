<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class PosController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly RegisterGate $gate,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $this->page->cart($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/scan', ['POST'])]
    public function scan(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->scan($session, $this->app->request()->field('scan'));
        }, 'Item added.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/price', ['POST'])]
    public function price(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->price($session, $this->lineNo(), Money::parseMinor($this->app->request()->field('price')));
        }, 'Price changed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/remove', ['POST'])]
    public function remove(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->remove($session, $this->lineNo());
        }, 'Item removed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/discard', ['POST'])]
    public function discard(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->discard($session);
        }, 'Ticket discarded.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/park', ['POST'])]
    public function park(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->park($session);
        }, 'Ticket parked.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/discount', ['POST'])]
    public function discount(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $request = $this->app->request();
            $posted = TicketAdjustment::discount(
                $request->field('discount_action'),
                $request->field('discount_kind'),
                $request->field('discount_value'),
                $request->field('discount_reason'),
            );
            $this->tickets->discount($session, $posted['kind'], $posted['value'], $posted['reason']);
        }, 'Discount saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/tax-free', ['POST'])]
    public function taxFree(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $request = $this->app->request();
            $this->tickets->waive($session, TicketAdjustment::waiver(
                $request->field('waiver_action'),
                $request->field('waiver_reason'),
            ));
        }, 'Tax updated.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/recall', ['POST'])]
    public function recall(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->recall($session, $this->ticketId());
        }, 'Ticket recalled.');
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

    private function lineNo(): int
    {
        $value = $this->app->request()->field('line_no');
        if (preg_match('/^[1-9][0-9]{0,5}$/', $value) !== 1) {
            throw new DatabaseRefusal('that item is not on the ticket');
        }

        return (int) $value;
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
