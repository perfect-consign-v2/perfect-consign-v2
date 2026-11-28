<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\ActorRole;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Inventory\CountWho;
use PerfectConsign\Feature\Inventory\ItemCount;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

/**
 * Close out vendor (owner and manager): count out what is left, hand back held
 * pieces, see each part clear, then Make inactive.
 */
final class CloseOutController
{
    private readonly PartySellerPaths $paths;

    public function __construct(
        private readonly MallApp $app,
        private readonly CloseOut $closeOut,
        private readonly ItemCount $counts,
        private readonly MallSettings $mall,
        private readonly Actor $actor,
    ) {
        $this->paths = new PartySellerPaths($mall);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/close-out', ['GET'])]
    public function show(string $slug, string $id): void
    {
        $this->page($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/close-out/count', ['POST'])]
    public function countOut(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $request = $this->app->request();
        $this->attempt($slug, $id, 'Item counted out.', function () use ($id, $request): void {
            $this->closeOut->countOut($id, $request->field('item_id'), $request->field('quantity_reason'), CountWho::of($this->actor));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/close-out/all', ['POST'])]
    public function tookEverything(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->attempt($slug, $id, 'Every item is marked Took it home.', function () use ($id): void {
            $this->closeOut->tookEverything($id, CountWho::of($this->actor));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/close-out/keep', ['POST'])]
    public function keep(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $itemId = $this->app->request()->field('item_id');
        $this->attempt($slug, $id, 'The store keeps that piece. It is now store stock.', function () use ($id, $itemId): void {
            $this->closeOut->keep($id, $itemId, $this->mall->today());
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/close-out/picked-up', ['POST'])]
    public function pickedUp(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $itemId = $this->app->request()->field('item_id');
        $this->attempt($slug, $id, 'Pickup saved.', function () use ($id, $itemId): void {
            $this->closeOut->pickedUp($id, $itemId, $this->mall->today(), CountWho::of($this->actor)->name);
        });
    }

    private function attempt(string $slug, string $id, string $notice, callable $work): void
    {
        $this->app->open($slug, true);
        $this->vendor($id);
        try {
            $work();
        } catch (DatabaseRefusal | \InvalidArgumentException $error) {
            $this->page($slug, $id, $this->app->catchRefusal($error));

            return;
        }
        $this->app->redirect($this->paths->record($slug, $id) . '/close-out', $notice);
    }

    /**
     * @return array{id: string, name: string, active: bool}
     */
    private function vendor(string $id): array
    {
        $vendor = $this->closeOut->vendor($id);
        if ($vendor === null) {
            $this->app->notFound();
        }

        return $vendor;
    }

    private function page(string $slug, string $id, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $vendor = $this->vendor($id);
        $who = CountWho::of($this->actor);
        $this->app->show('Feature/Party/close-out', [
            'title' => 'Close out ' . $vendor['name'],
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'record' => $this->paths->record($tenant->slug, $id),
            'items' => $this->closeOut->items($id),
            'held' => $this->closeOut->held($id),
            'open' => $this->closeOut->open($id),
            'reasons' => $this->counts->reasons($who),
            'keepFrom' => $this->actor->role() === ActorRole::OWNER ? $this->closeOut->keepFrom($id) : '',
            'today' => $this->mall->today(),
            'deposits' => $this->closeOut->depositsOn(),
            'error' => $error,
        ]);
    }
}
