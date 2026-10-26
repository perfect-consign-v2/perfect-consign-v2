<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use InvalidArgumentException;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class ExpireDeskController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly ExpireDesk $desk,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/expired', ['GET'])]
    public function show(string $slug): void
    {
        $this->page($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})/dispose', ['POST'])]
    public function dispose(string $slug, string $itemId): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $kind = $this->app->request()->field('kind');
        try {
            if ($kind === 'buy') {
                $this->desk->buy(
                    $itemId,
                    $this->app->request()->field('amount'),
                    $this->postedOn(),
                    $this->formToken(),
                );
                $this->app->redirect('/' . $slug . '/inventory/expired', 'Bought for the store.');
            }
            $this->desk->dispose($itemId, $kind, $this->postedOn());
            $notice = $kind === 'donate' ? 'Marked donated.' : 'Returned to the vendor.';
            $this->app->redirect('/' . $slug . '/inventory/expired', $notice);
        } catch (DatabaseRefusal $refusal) {
            $this->page($slug, $this->app->catchRefusal($refusal));
        } catch (InvalidArgumentException $error) {
            $this->page($slug, $error->getMessage());
        }
    }

    private function page(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Inventory/expire-desk', [
            'title' => 'Expired items',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'items' => $this->desk->queue(),
            'error' => $error,
            'formToken' => $this->formToken(),
            'today' => $this->mall->today(),
        ]);
    }

    private function postedOn(): string
    {
        $date = $this->app->request()->field('posted_on');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return $this->mall->today();
    }

    private function formToken(): string
    {
        $token = $this->app->request()->field('form_token');
        if (preg_match('/^[0-9a-f]{32}$/', $token) === 1) {
            return $token;
        }

        return bin2hex(random_bytes(16));
    }
}
