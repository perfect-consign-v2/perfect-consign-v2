<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Offer;

use InvalidArgumentException;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Request;

class OfferController
{
    public function __construct(
        protected readonly MallApp $app,
        protected readonly OfferStore $offers,
        protected readonly MallSettings $mall,
        protected readonly Actor $actor,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/offers', ['GET'])]
    public function show(string $slug): void
    {
        $this->list($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/offers/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->app->open($slug, true);
        $this->form($slug, null, null, $this->blank(), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/offers', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->keep($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/offers/([0-9a-fA-F-]{36})', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->openForm($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/offers/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->keep($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/offers/([0-9a-fA-F-]{36})/end', ['POST'])]
    public function end(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $this->offers->end($id, $this->mall->today(), null);
        } catch (DatabaseRefusal $refusal) {
            $this->list($slug, $this->app->catchRefusal($refusal), null);

            return;
        } catch (InvalidArgumentException $error) {
            $this->list($slug, $error->getMessage(), null);

            return;
        }
        $this->app->redirect('/' . $slug . '/offers', 'Deal ended.');
    }

    protected function list(string $slug, ?string $error, ?string $vendorId): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Offer/offers', [
            'title' => 'Deals',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'offers' => $this->offers->offers($vendorId, $this->mall->today()),
            'base' => $this->base($slug, $vendorId),
            'error' => $error,
        ]);
    }

    protected function openForm(string $slug, string $id, ?string $vendorId): void
    {
        $this->app->open($slug, true);
        $offer = $this->offers->find($id, $vendorId);
        if ($offer === null) {
            $this->app->notFound();
        }
        $this->form($slug, $id, $vendorId, [
            'name' => $offer['name'],
            'scope' => $offer['scope'],
            'kind' => $offer['kind'],
            'threshold' => (string) $offer['threshold'],
            'price' => number_format($offer['deal_minor'] / 100, 2, '.', ''),
            'funded_by' => $offer['funded_by'],
            'starts_on' => $offer['starts_on'],
            'ends_on' => $offer['ends_on'],
            'item_code' => $offer['item_code'],
            'vendor_id' => $offer['vendor_id'],
        ], null);
    }

    /**
     * @param array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string} $draft
     */
    protected function form(string $slug, ?string $id, ?string $vendorId, array $draft, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Offer/offer-form', [
            'title' => $id === null ? 'New deal' : 'Edit deal',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'base' => $this->base($slug, $vendorId),
            'id' => $id ?? '',
            'draft' => $draft,
            'vendors' => $vendorId === null ? $this->offers->vendors() : [],
            'locked' => $vendorId !== null,
            'error' => $error,
        ]);
    }

    protected function keep(string $slug, ?string $id, ?string $vendorId): void
    {
        $this->app->open($slug, true);
        $draft = $this->posted($this->app->request());
        try {
            $this->offers->save($id, $draft, $vendorId);
        } catch (DatabaseRefusal $refusal) {
            $this->form($slug, $id, $vendorId, $draft, $this->app->catchRefusal($refusal));

            return;
        } catch (InvalidArgumentException $error) {
            $this->form($slug, $id, $vendorId, $draft, $error->getMessage());

            return;
        }
        $this->app->redirect($this->base($slug, $vendorId), 'Deal saved.');
    }

    /**
     * @return array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string}
     */
    protected function posted(Request $request): array
    {
        return [
            'name' => $request->field('name'),
            'scope' => $request->field('scope'),
            'kind' => $request->field('kind'),
            'threshold' => $request->field('threshold'),
            'price' => $request->field('price'),
            'funded_by' => $request->field('funded_by'),
            'starts_on' => $request->field('starts_on'),
            'ends_on' => $request->field('ends_on'),
            'item_code' => $request->field('item_code'),
            'vendor_id' => $request->field('vendor_id'),
        ];
    }

    /**
     * @return array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string}
     */
    protected function blank(): array
    {
        return [
            'name' => '',
            'scope' => 'item',
            'kind' => 'bundle',
            'threshold' => '3',
            'price' => '',
            'funded_by' => 'vendor',
            'starts_on' => $this->mall->today(),
            'ends_on' => '',
            'item_code' => '',
            'vendor_id' => '',
        ];
    }

    protected function vendor(string $slug): string
    {
        $this->app->open($slug, true);
        $vendorId = $this->actor->vendor();
        if ($vendorId === '') {
            $this->app->notFound();
        }

        return $vendorId;
    }

    protected function base(string $slug, ?string $vendorId): string
    {
        if ($vendorId === null) {
            return '/' . $slug . '/offers';
        }

        return '/' . $slug . '/vendor/offers';
    }
}
