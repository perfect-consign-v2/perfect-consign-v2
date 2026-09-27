<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class MallController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $this->form($slug, $this->mall->timeZone(), $this->mall->boothWidth(), $this->mall->boothDepth(), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $timeZone = $this->app->request()->field('time_zone');
        $width = $this->feet($this->app->request()->field('booth_width_ft'));
        $depth = $this->feet($this->app->request()->field('booth_depth_ft'));
        $error = null;
        if ($width === null || $depth === null) {
            $error = 'booth size is whole feet';
        }
        if ($error === null && $width !== null && $depth !== null) {
            try {
                $this->mall->save($timeZone, $width, $depth);
            } catch (DatabaseRefusal $refusal) {
                $error = $this->app->catchRefusal($refusal);
            } catch (\InvalidArgumentException $errorValue) {
                $error = $errorValue->getMessage();
            }
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings');
        }
        $this->form($slug, $timeZone, $width ?? 0, $depth ?? 0, $error);
    }

    private function feet(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function form(string $slug, string $timeZone, int $width, int $depth, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Mall/settings', [
            'title' => 'Settings',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'timeZone' => $timeZone,
            'boothWidth' => $width,
            'boothDepth' => $depth,
            'error' => $error,
        ]);
    }
}
