<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\ResponseSent;
use Psr\Log\LoggerInterface;

final class PosController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PosPage $page,
        private readonly TicketStore $tickets,
        private readonly RegisterGate $gate,
        private readonly SaleHold $sales,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $this->page->cart($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/desk', ['GET'])]
    public function desk(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/desk', [
            'title' => 'Sell',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/ticket\.json', ['GET'])]
    public function ticket(string $slug): void
    {
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        $this->sendJson(['ticket' => $this->tickets->cart($session)]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/scan', ['POST'])]
    public function scan(string $slug): void
    {
        $this->act($slug, function (string $session): void {
            $this->tickets->scan($session, $this->app->request()->field('scan'));
        });
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
            $this->tickets->park($session, $this->app->request()->field('park_label'));
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
    private function act(string $slug, callable $work, ?string $notice = null): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $this->sales->refuse($slug);
            $work($session);
        } catch (DatabaseRefusal $refusal) {
            $message = $this->app->catchRefusal($refusal);
            $this->logger->error('sale post failed', ['slug' => $slug, 'message' => $message]);
            if ($this->app->request()->wantsJson()) {
                $this->sendJson(['error' => $message, 'ticket' => $this->tickets->cart($session)], 422);
            }
            $this->page->cart($slug, $message);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->logger->error('sale post failed', ['slug' => $slug, 'message' => $error->getMessage()]);
            if ($this->app->request()->wantsJson()) {
                $this->sendJson(['error' => $error->getMessage()], 422);
            }
            $this->page->cart($slug, $error->getMessage());
            return;
        }
        if ($this->app->request()->wantsJson()) {
            $this->sendJson(['notice' => $notice, 'ticket' => $this->tickets->cart($session)]);
        }
        $this->app->redirect('/' . $slug . '/pos', $notice);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJson(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            http_response_code(500);
            echo '{"error":"The ticket could not be sent."}';
        }
        throw new ResponseSent('json');
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
