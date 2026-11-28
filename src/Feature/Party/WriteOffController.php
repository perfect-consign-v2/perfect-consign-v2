<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\ActorRole;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

/**
 * The owner writes off what a vendor owes, or takes some back when they pay
 * after a write-off. The database refuses everyone else and every wrong amount.
 */
final class WriteOffController
{
    private readonly PartySellerPaths $paths;

    public function __construct(
        private readonly MallApp $app,
        private readonly BadDebts $badDebts,
        private readonly VendorMoveOut $moveOut,
        private readonly CloseOut $closeOut,
        private readonly MallSettings $mall,
        private readonly Actor $actor,
    ) {
        $this->paths = new PartySellerPaths($mall);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/write-off', ['GET'])]
    public function show(string $slug, string $id): void
    {
        $this->page($slug, $id, null, '');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/write-off', ['POST'])]
    public function writeOff(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $vendor = $this->owned($slug, $id);
        $reason = $this->app->request()->field('reason');
        $total = 0;
        foreach ($this->moveOut->debts($id, $this->mall->today()) as $debt) {
            $total += $debt['open'];
        }
        try {
            $this->badDebts->writeOff($id, $reason, $this->mall->today());
        } catch (DatabaseRefusal $refusal) {
            $this->page($slug, $id, $this->app->catchRefusal($refusal), $reason);

            return;
        }
        $first = explode(' ', $vendor['name'])[0];
        $this->app->redirect(
            $this->paths->record($slug, $id),
            Money::format($total) . ' written off.' . ($this->closeOut->open($id) === [] ? ' ' . $first . ' can now be made inactive.' : '')
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/write-offs/([0-9a-fA-F-]{36})/recover', ['POST'])]
    public function recover(string $slug, string $id, string $journal): void
    {
        $this->app->checkCsrf();
        $this->owned($slug, $id);
        try {
            $amount = Money::parseMinor($this->app->request()->field('amount'));
            $this->badDebts->recover($journal, $amount, $this->mall->today());
        } catch (DatabaseRefusal | \InvalidArgumentException $error) {
            $this->app->redirect($this->paths->record($slug, $id), null, $this->app->catchRefusal($error));
        }
        $this->app->redirect(
            $this->paths->record($slug, $id),
            Money::format($amount) . ' taken back. Take their payment on the rent page or at the next payout.'
        );
    }

    /**
     * @return array{id: string, name: string, active: bool}
     */
    private function owned(string $slug, string $id): array
    {
        $this->app->open($slug, true);
        $vendor = $this->closeOut->vendor($id);
        if ($vendor === null || $this->actor->role() !== ActorRole::OWNER) {
            $this->app->notFound();
        }

        return $vendor;
    }

    private function page(string $slug, string $id, ?string $error, string $reason): void
    {
        $vendor = $this->owned($slug, $id);
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $this->app->show('Feature/Party/write-off', [
            'title' => 'Write off ' . $vendor['name'],
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'record' => $this->paths->record($tenant->slug, $id),
            'debts' => $this->moveOut->debts($id, $today),
            'open' => $this->closeOut->open($id),
            'today' => $today,
            'reason' => $reason,
            'error' => $error,
        ]);
    }
}
