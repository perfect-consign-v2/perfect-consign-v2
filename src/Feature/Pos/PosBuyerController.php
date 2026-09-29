<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PosBuyerController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly TicketBuyer $buyers,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/buyer', ['POST'])]
    public function save(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $cart = $this->tickets->cart($session);
            $vendorId = $this->app->request()->field('vendor_id');
            if ($vendorId === '') {
                $this->buyers->clear($cart['ticket_id']);

                return;
            }
            $this->buyers->save($cart['ticket_id'], $vendorId);
        });
    }

    /**
     * @param callable(string): void $work
     */
    private function act(string $slug, callable $work): void
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
        $this->app->redirect('/' . $slug . '/pos', 'Buyer saved.');
    }
}
