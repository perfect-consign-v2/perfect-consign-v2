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
        }, 'Piece added.');
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
        }, 'Piece removed.');
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
            throw new DatabaseRefusal('that piece is not on the ticket');
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
