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
        $this->form(
            $slug,
            $this->mall->timeZone(),
            $this->mall->boothWidth(),
            $this->mall->boothDepth(),
            $this->mall->floorWidth(),
            $this->mall->floorDepth(),
            null
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $timeZone = $this->app->request()->field('time_zone');
        $width = $this->feet($this->app->request()->field('booth_width_ft'));
        $depth = $this->feet($this->app->request()->field('booth_depth_ft'));
        $floorWidth = $this->feet($this->app->request()->field('floor_width_ft'));
        $floorDepth = $this->feet($this->app->request()->field('floor_depth_ft'));
        $error = $this->store($timeZone, $width, $depth, $floorWidth, $floorDepth);
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings', 'Settings saved.');
        }
        $this->form($slug, $timeZone, $width ?? 0, $depth ?? 0, $floorWidth ?? 0, $floorDepth ?? 0, $error);
    }

    private function store(string $timeZone, ?int $width, ?int $depth, ?int $floorWidth, ?int $floorDepth): ?string
    {
        if ($width === null || $depth === null) {
            return 'booth size is whole feet';
        }
        if ($floorWidth === null || $floorDepth === null) {
            return 'floor size is whole feet';
        }
        try {
            $this->mall->save($timeZone, $width, $depth, $floorWidth, $floorDepth);
        } catch (DatabaseRefusal $refusal) {
            return $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            return $errorValue->getMessage();
        }

        return null;
    }

    private function feet(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function form(
        string $slug,
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        ?string $error,
    ): void {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Mall/settings', [
            'title' => 'Settings',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'timeZone' => $timeZone,
            'boothWidth' => $width,
            'boothDepth' => $depth,
            'floorWidth' => $floorWidth,
            'floorDepth' => $floorDepth,
            'error' => $error,
        ]);
    }
}
