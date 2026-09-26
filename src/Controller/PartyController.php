<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\MallApp;

final class PartyController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/parties', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors', ['POST'])]
    public function addVendor(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, function (): void {
            $this->app->books()->addVendor(
                $this->app->request()->field('code'),
                $this->app->request()->field('name')
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths', ['POST'])]
    public function addBooth(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, function (): void {
            $this->app->books()->addBooth(
                $this->app->request()->field('code'),
                $this->app->request()->field('name')
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments', ['POST'])]
    public function addAssignment(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, function (): void {
            $this->app->books()->addAssignment(
                $this->app->request()->field('booth_id'),
                $this->app->request()->field('vendor_id'),
                $this->app->request()->field('starts_on'),
                $this->app->request()->field('ends_on')
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments/([0-9a-fA-F-]{36})/end', ['POST'])]
    public function endAssignment(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->change($slug, function () use ($id): void {
            $this->app->books()->endAssignment($id, $this->app->request()->field('ends_on'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rates', ['POST'])]
    public function addRate(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, function (): void {
            $amount = $this->app->request()->field('amount');
            $this->app->books()->addRate(
                $this->app->request()->field('booth_id'),
                $this->app->request()->field('month_start'),
                \PerfectConsign\Ledger\Money::parseMinor($amount)
            );
        });
    }

    private function change(string $slug, callable $action): void
    {
        $this->app->open($slug, true);
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/parties');
        }
        $this->render($slug, $error);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $books = $this->app->books();
        $this->app->show('parties', [
            'title' => 'Vendors and booths',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendors' => $books->vendors(),
            'booths' => $books->booths(),
            'assignments' => $books->assignments(),
            'rates' => $books->rates(),
            'error' => $error,
        ]);
    }
}
