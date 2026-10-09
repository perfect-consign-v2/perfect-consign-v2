<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PosCustomerController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly TicketCustomer $customers,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/customer', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $this->sales->refuse($slug);
            $cart = $this->tickets->cart($session);
            $request = $this->app->request();
            $this->customers->save(
                $cart['ticket_id'],
                $request->field('customer_name'),
                $request->field('customer_phone'),
                $request->field('customer_email'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->page->cart($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/pos', 'Customer saved.');
    }
}
