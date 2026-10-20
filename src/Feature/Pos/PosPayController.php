<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

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
        private readonly ReceiptChange $change,
        private readonly ReceiptView $receipts,
        private readonly ReceiptMailer $mailer,
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
            $this->page->payment($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->payment($slug, $error->getMessage());

            return;
        }
        $this->app->redirect($next['path'], $next['notice']);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})', ['GET'])]
    public function receipt(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $this->receipts->show($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/receipt/([0-9a-fA-F-]{36})/email', ['POST'])]
    public function email(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            $this->mailer->send(
                $this->poster->receipt($id),
                $this->app->request()->field('email'),
                $tenant->displayName,
                $this->app->getCompanyLines(),
                $this->app->getReceiptFooter(),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->receipts->show($slug, $id, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->receipts->show($slug, $id, $error->getMessage());

            return;
        } catch (\RuntimeException $error) {
            $message = $error->getMessage() === 'smtp is not configured'
                ? 'the server mailbox is not set up'
                : 'the receipt was not sent';
            $this->receipts->show($slug, $id, $message);

            return;
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Receipt emailed.');
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
            $this->receipts->show($slug, $id, $this->app->catchRefusal($refusal));
            return;
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
            $credit = $this->tenders->credit($this->app->request(), $this->change->owed($this->poster->receipt($id), $this->itemId()));
            $this->poster->returnItem($session, $this->mall->today(), $id, $this->itemId(), $credit['line'], $credit['check']);
        } catch (DatabaseRefusal $refusal) {
            $this->receipts->show($slug, $id, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->receipts->show($slug, $id, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Item returned.');
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
