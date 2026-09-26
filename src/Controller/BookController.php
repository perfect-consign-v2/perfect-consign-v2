<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\MallApp;

final class BookController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/book', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $books = $this->app->books();
        $this->app->show('book', [
            'title' => 'Book',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'accounts' => $books->accounts(),
            'periods' => $books->periods(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/periods', ['POST'])]
    public function addPeriod(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $error = null;
        try {
            $this->app->books()->addPeriod(
                $this->app->request()->field('starts_on'),
                $this->app->request()->field('ends_on')
            );
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/book');
        }
        $books = $this->app->books();
        $this->app->show('book', [
            'title' => 'Book',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'accounts' => $books->accounts(),
            'periods' => $books->periods(),
            'error' => $error,
        ]);
    }
}
