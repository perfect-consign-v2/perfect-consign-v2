<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Offer;

use InvalidArgumentException;
use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;

final class VendorOfferController extends OfferController
{
    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/offers', ['GET'])]
    public function vendorShow(string $slug): void
    {
        $this->list($slug, null, $this->vendor($slug));
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
        try {
            $this->offers->end($id, $this->mall->today(), $vendorId);
        } catch (DatabaseRefusal $refusal) {
            $this->list($slug, $this->app->catchRefusal($refusal), $vendorId);

            return;
        } catch (InvalidArgumentException $error) {
            $this->list($slug, $error->getMessage(), $vendorId);

            return;
        }
        $this->app->redirect('/' . $slug . '/vendor/offers', 'Deal ended.');
    }
}
