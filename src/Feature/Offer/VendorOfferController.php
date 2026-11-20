<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Offer;

use PerfectApp\Routing\Route;
use PerfectConsign\Shared\Http\JsonResponse;

final class VendorOfferController extends OfferController
{
    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers', ['GET'])]
    public function vendorShow(string $slug): void
    {
        $this->list($slug, null, $this->vendor($slug));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers/items\.json', ['GET'])]
    public function vendorItems(string $slug): void
    {
        $vendorId = $this->vendor($slug);
        JsonResponse::send(
            $this->offers->itemChoices($vendorId, $this->app->request()->query('q')),
            200,
            'The items could not be sent.',
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers/new', ['GET'])]
    public function vendorCreate(string $slug): void
    {
        $vendorId = $this->vendor($slug);
        $this->form($slug, null, $vendorId, $this->blank(), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers', ['POST'])]
    public function vendorAdd(string $slug): void
    {
        $this->app->checkCsrf();
        $this->keep($slug, null, $this->vendor($slug));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers/([0-9a-fA-F-]{36})', ['GET'])]
    public function vendorEdit(string $slug, string $id): void
    {
        $this->openForm($slug, $id, $this->vendor($slug));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers/([0-9a-fA-F-]{36})', ['POST'])]
    public function vendorSave(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->keep($slug, $id, $this->vendor($slug));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers/([0-9a-fA-F-]{36})/end', ['POST'])]
    public function vendorEnd(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $vendorId = $this->vendor($slug);
        $this->endOffer($slug, $id, $vendorId, $this->preview->url('/' . $slug . '/vendor/offers'));
    }
}
