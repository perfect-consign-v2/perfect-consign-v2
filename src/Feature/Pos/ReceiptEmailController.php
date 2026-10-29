<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Mail\OutboundMail;

final class ReceiptEmailController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly SalePoster $poster,
        private readonly ReceiptView $receipts,
        private readonly ReceiptMailer $mailer,
    ) {
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
            $message = $error->getMessage() === OutboundMail::SMTP_NOT_CONFIGURED
                ? 'the server mailbox is not set up'
                : 'the receipt email could not be sent';
            $this->receipts->show($slug, $id, $message);

            return;
        }
        $this->app->redirect('/' . $slug . '/pos/receipt/' . $id, 'Receipt emailed.');
    }
}
