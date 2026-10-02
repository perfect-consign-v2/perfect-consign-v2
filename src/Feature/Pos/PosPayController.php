<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Scalar\RowValue;

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
            $message = $this->app->catchRefusal($refusal);
            if ($message === RegisterStore::GIFT_CERTIFICATE_MISSING) {
                $this->page->payment($slug, $message);

                return;
            }
            $this->page->cart($slug, $message);

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->cart($slug, $error->getMessage());
            return;
        }
        $this->app->redirect($next['path'], $next['notice']);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})', ['GET'])]
    public function receipt(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $this->showReceipt($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})/void', ['POST'])]
    public function voidSale(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $this->poster->voidSale($session, $this->mall->today(), $id);
        } catch (DatabaseRefusal $refusal) {
            $this->showReceipt($slug, $id, $this->app->catchRefusal($refusal));
            return;
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Ticket voided.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})/return', ['POST'])]
    public function returnPiece(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        try {
            $credit = $this->tenders->credit($this->app->request(), $this->returnAmount($id));
            $this->poster->returnPiece($session, $this->mall->today(), $id, $this->itemId(), $credit['line'], $credit['check']);
        } catch (DatabaseRefusal $refusal) {
            $this->showReceipt($slug, $id, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->showReceipt($slug, $id, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Piece returned.');
    }

    private function showReceipt(string $slug, string $id, ?string $error): void
    {
        try {
            $receipt = $this->poster->receipt($id);
        } catch (DatabaseRefusal $refusal) {
            if ($refusal->getMessage() === 'that ticket is not a sale') {
                $this->app->notFound();
            }
            throw $refusal;
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/receipt', [
            'title' => 'Receipt',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'receipt' => $receipt,
            'change' => $this->change($this->app->request()->query('tendered'), $receipt['cash']),
            'error' => $error,
        ]);
    }

    private function change(string $tendered, int $cash): ?int
    {
        if ($tendered === '' || $cash < 1 || !$this->amount($tendered)) {
            return null;
        }
        $handed = Money::parseMinor($tendered);
        if ($handed < $cash) {
            return null;
        }

        return $handed - $cash;
    }

    private function amount(string $value): bool
    {
        if ($value === '0' || $value === '0.0' || $value === '0.00') {
            return false;
        }

        return preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value) === 1;
    }

    private function itemId(): string
    {
        $id = $this->app->request()->field('item_id');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new DatabaseRefusal('that piece is not on the ticket');
        }

        return $id;
    }

    private function returnAmount(string $saleId): int
    {
        $itemId = $this->itemId();
        foreach ($this->poster->receipt($saleId)['lines'] as $line) {
            if ($line['item_id'] === $itemId) {
                return RowValue::integer($line['price']) - RowValue::integer($line['discount']) + RowValue::integer($line['tax']);
            }
        }

        throw new DatabaseRefusal('that piece is not on the ticket');
    }
}
