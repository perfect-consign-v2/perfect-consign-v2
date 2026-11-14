<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class TagImportController
{
    private bool $acceptLocalFile = false;

    public function __construct(
        private readonly MallApp $app,
        private readonly TagImport $import,
    ) {
    }

    public function acceptLocalFile(bool $accept): void
    {
        $this->acceptLocalFile = $accept;
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
        $error = $this->uploadError($file);
        if ($error !== null || $file === null) {
            $this->page($slug, $error ?? 'Choose a CSV file.');

            return;
        }
        try {
            $count = $this->import->load($file['tmp_name']);
        } catch (DatabaseRefusal $refusal) {
            $this->page($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $caught) {
            $this->page($slug, $caught->getMessage());

            return;
        }
        $label = $count === 1 ? '1 tag brought in.' : $count . ' tags brought in.';
        $this->app->redirect('/' . $slug . '/inventory', $label);
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $file
     */
    private function uploadError(?array $file): ?string
    {
        if ($file === null || !$this->uploadAccepted($file)) {
            return 'Choose a CSV file.';
        }
        if (!$this->csvFits($file['tmp_name'])) {
            return 'That CSV is too large.';
        }
        if ($this->sampleIsBinary($file['tmp_name'])) {
            return 'Choose a CSV file.';
        }

        return null;
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string} $file
     */
    private function uploadAccepted(array $file): bool
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->acceptLocalFile || is_uploaded_file($file['tmp_name']);
    }

    private function csvFits(string $path): bool
    {
        $size = filesize($path);

        return is_int($size) && $size >= 1 && $size <= 2_000_000;
    }

    private function sampleIsBinary(string $path): bool
    {
        $sample = file_get_contents($path, false, null, 0, 512);

        return !is_string($sample) || str_contains($sample, "\0");
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
