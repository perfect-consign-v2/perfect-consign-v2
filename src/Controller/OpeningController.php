<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\MallApp;

final class OpeningController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/opening', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/opening', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            if (!$this->app->books()->openingAllowed()) {
                throw new DatabaseRefusal('an opening is available only while the book has no other journal');
            }
            $journalId = $this->app->books()->post(
                'opening',
                $this->app->request()->field('posting_date'),
                'opening',
                'opening',
                'Opening balance',
                PostedLines::read($this->app->request(), 6, true)
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $tenant->slug . '/journals/' . $journalId);
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $books = $this->app->books();
        $this->app->show('opening', [
            'title' => 'Opening balance',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'allowed' => $books->openingAllowed(),
            'accounts' => $books->postableAccounts(false),
            'vendors' => $books->vendors(),
            'booths' => $books->booths(),
            'error' => $error,
        ]);
    }
}
