<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\JsonResponse;

final class PosPayController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly TenderSplit $tenders,
        private readonly SalePoster $poster,
        private readonly MallSettings $mall,
        private readonly SaleCheckout $checkout,
        private readonly ReceiptView $receipts,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/pay', ['POST'])]
    public function pay(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $next = $this->checkout->pay($slug, $session, $this->app->request());
        } catch (DatabaseRefusal $refusal) {
            $this->fail($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->fail($slug, $error->getMessage());

            return;
        }
        if ($this->app->request()->wantsJson()) {
            $this->sendJson([
                'notice' => $next['notice'],
                'path' => $next['path'],
                'journal_id' => $next['journal_id'],
                'journal_no' => $next['journal_no'],
                'posted' => $next['journal_no'] > 0,
            ]);
        }
        $this->app->redirect($next['path'], $next['notice']);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})', ['GET'])]
    public function receipt(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $this->receipts->show($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})/void', ['POST'])]
    public function voidSale(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $request = $this->app->request();
            $this->poster->voidSale(
                $session,
                $this->mall->today(),
                $id,
                $request->field('override_user'),
                $request->field('override_password'),
            );
        } catch (DatabaseRefusal $refusal) {
            $message = $this->app->catchRefusal($refusal);
            if ($this->app->request()->wantsJson()) {
                $this->sendJson(['error' => $message], 422);
            }
            $this->receipts->show($slug, $id, $message);
            return;
        }
        if ($this->app->request()->wantsJson()) {
            $this->sendJson(['notice' => 'Ticket voided.', 'posted' => true]);
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Ticket voided.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})/return', ['POST'])]
    public function returnItem(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $credit = $this->tenders->credit($this->app->request(), $this->poster->refundAmount($id, $this->itemId()));
            $this->poster->returnItem($session, $this->mall->today(), $id, $this->itemId(), $credit['line'], $credit['check']);
        } catch (DatabaseRefusal $refusal) {
            $message = $this->app->catchRefusal($refusal);
            if ($this->app->request()->wantsJson()) {
                $this->sendJson(['error' => $message], 422);
            }
            $this->receipts->show($slug, $id, $message);
            return;
        } catch (\InvalidArgumentException $error) {
            if ($this->app->request()->wantsJson()) {
                $this->sendJson(['error' => $error->getMessage()], 422);
            }
            $this->receipts->show($slug, $id, $error->getMessage());
            return;
        }
        if ($this->app->request()->wantsJson()) {
            $this->sendJson(['notice' => 'Item returned.', 'posted' => true]);
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Item returned.');
    }

    private function fail(string $slug, string $message): void
    {
        if ($this->app->request()->wantsJson()) {
            $this->sendJson(['error' => $message], 422);
        }
        $this->page->payment($slug, $message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJson(array $payload, int $status = 200): never
    {
        JsonResponse::send($payload, $status, 'The ticket could not be sent.');
    }

    private function itemId(): string
    {
        $id = $this->app->request()->field('item_id');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new DatabaseRefusal('that item is not on the ticket');
        }

        return $id;
    }

}
