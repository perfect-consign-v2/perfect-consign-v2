<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class LetterController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LetterDesk $desk,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/letters', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->compose($tenant->displayName, $slug, null, $this->app->request()->query('vendor'));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/letters', ['POST'])]
    public function file(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            $id = $this->desk->fileLetter(
                $tenant->slug,
                $this->mall->today(),
                $this->app->request()->field('subject'),
                $this->app->request()->field('message'),
                $this->app->request()->field('everyone') === '1',
                $this->postedIds()
            );
        } catch (\InvalidArgumentException $error) {
            $this->compose($tenant->displayName, $slug, $error->getMessage(), '');

            return;
        } catch (DatabaseRefusal $refusal) {
            $this->compose($tenant->displayName, $slug, $this->app->catchRefusal($refusal), '');

            return;
        }
        $this->app->redirect('/' . $slug . '/letters/' . $id, 'The letter is filed. It goes out on the mail run.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/letters/([0-9a-fA-F-]{36})', ['GET'])]
    public function read(string $slug, string $id): void
    {
        $tenant = $this->app->open($slug, true);
        $letter = $this->desk->openLetter($id);
        if ($letter === null) {
            $this->app->notFound();
        }
        $this->app->show('Feature/Party/letter', [
            'title' => 'Email',
            'slug' => $slug,
            'displayName' => $tenant->displayName,
            'letter' => $letter,
        ]);
    }

    private function compose(string $displayName, string $slug, ?string $error, string $selected): void
    {
        $this->app->show('Feature/Party/letters', [
            'title' => 'Email',
            'slug' => $slug,
            'displayName' => $displayName,
            'error' => $error,
            'people' => $this->desk->people(),
            'letters' => $this->desk->letters(),
            'selected' => strtolower($selected),
            'party' => $this->mall->getPartyLabel('vendor', 'consignor'),
            'partyLabel' => $this->mall->getPartyLabel('Vendors', 'Consignors'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function postedIds(): array
    {
        $posted = $this->app->request()->post['vendor_id'] ?? [];
        if (is_string($posted)) {
            $posted = [$posted];
        }
        if (!is_array($posted)) {
            return [];
        }
        $ids = [];
        foreach ($posted as $id) {
            if (is_string($id)) {
                $trimmed = trim($id);
                if ($trimmed !== '') {
                    $ids[] = strtolower($trimmed);
                }
            }
        }

        return $ids;
    }
}
