<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PosLineController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly TicketLines $lines,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/quantity', ['POST'])]
    public function quantity(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $cart = $this->tickets->cart($session);
            $this->lines->quantity($cart['ticket_id'], $this->lineNo(), $this->units());
        }, 'Quantity saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/line-discount', ['POST'])]
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
            $this->lines->discount(
                $this->tickets->cart($session)['ticket_id'],
                $this->lineNo(),
                $posted['kind'],
                $posted['value'],
                $posted['reason'],
            );
        }, 'Line discount saved.');
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

    private function lineNo(): int
    {
        $value = $this->app->request()->field('line_no');
        if (preg_match('/^[1-9][0-9]{0,5}$/', $value) !== 1) {
            throw new DatabaseRefusal('that item is not on the ticket');
        }

        return (int) $value;
    }

    private function units(): int
    {
        $value = $this->app->request()->field('quantity');
        if (preg_match('/^[1-9][0-9]{0,5}$/', $value) !== 1) {
            throw new DatabaseRefusal('there are not enough on hand');
        }

        return (int) $value;
    }
}
