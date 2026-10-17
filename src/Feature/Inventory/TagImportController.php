<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class TagImportController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly TagImport $import,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/import', ['GET'])]
    public function show(string $slug): void
    {
        $this->page($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/import', ['POST'])]
    public function load(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $file = $this->app->request()->upload('tags');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->page($slug, 'Choose a CSV file.');

            return;
        }
        try {
            $count = $this->import->load($file['tmp_name']);
        } catch (DatabaseRefusal $refusal) {
            $this->page($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page($slug, $error->getMessage());

            return;
        }
        $label = $count === 1 ? '1 tag brought in.' : $count . ' tags brought in.';
        $this->app->redirect('/' . $slug . '/inventory', $label);
    }

    private function page(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Inventory/import', [
            'title' => 'Bring in tags',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => $error,
        ]);
    }
}
