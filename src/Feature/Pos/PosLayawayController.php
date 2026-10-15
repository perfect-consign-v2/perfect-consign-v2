<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PosLayawayController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly LayawayBook $layaways,
        private readonly LayawaySlip $slips,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/layaway/([0-9a-fA-F-]{36})', ['GET'])]
    public function slip(string $slug, string $ticketId): void
    {
        $this->app->open($slug, true);
        try {
            $slip = $this->slips->read($ticketId);
        } catch (DatabaseRefusal $refusal) {
            $this->page->cart($slug, $this->app->catchRefusal($refusal));

            return;
        }
        $this->show($slug, $slip);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/layaway/cancel', ['POST'])]
    public function cancel(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $this->sales->refuse($slug);
            $ticketId = $this->ticketId();
            $slip = $this->slips->read($ticketId);
            $this->layaways->cancel($this->gate->session($slug), $this->mall->today(), $ticketId);
            $slip = $this->slips->cancelled($slip);
        } catch (DatabaseRefusal $refusal) {
            $this->page->cart($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->cart($slug, $error->getMessage());

            return;
        }
        $this->show($slug, $slip);
    }

    /**
     * @param array{
     *     ticket_id: string,
     *     customer: string,
     *     cancelled: bool,
     *     lines: list<array{description: string, code: string, quantity: int, amount: int}>,
     *     due: int,
     *     deposits: int,
     *     balance: int,
     *     payments: list<array{posted_on: string, tender: string, amount: int, kind: string}>
     * } $slip
     */
    private function show(string $slug, array $slip): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/layaway-slip', [
            'title' => 'Layaway receipt',
            'displayName' => $tenant->displayName,
            'companyLines' => $this->app->getCompanyLines(),
            'slug' => $tenant->slug,
            'showSearch' => false,
            'slip' => $slip,
        ]);
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
