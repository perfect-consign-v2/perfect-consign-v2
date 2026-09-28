<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Backup\BackupHeadline;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Ledger\DatabaseRefusal;

final class MallController
{
    private ?string $postedCashRound = null;

    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly BackupStore $backups,
    ) {
    }

    public function showError(string $slug, string $error): void
    {
        $this->app->open($slug, true);
        $this->form(
            $slug,
            $this->mall->timeZone(),
            $this->mall->boothWidth(),
            $this->mall->boothDepth(),
            $this->mall->floorWidth(),
            $this->mall->floorDepth(),
            $this->percent($this->mall->storeTake()),
            $this->dayText($this->mall->floorDays()),
            $error
        );
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
            $this->percent($this->mall->storeTake()),
            $this->dayText($this->mall->floorDays()),
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
        $storeTake = $this->app->request()->field('default_store_take');
        $floorRaw = $this->app->request()->field('floor_days');
        $basis = $this->basisPoints($storeTake);
        $days = $this->wholeDays($floorRaw);
        $error = $this->refusal($basis, $days, $timeZone, $width, $depth, $floorWidth, $floorDepth);
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings', 'Settings saved.');
        }
        $this->postedCashRound = $this->app->request()->field('cash_round_nickel') === '1' ? '1' : '';
        $this->form($slug, $timeZone, $width ?? 0, $depth ?? 0, $floorWidth ?? 0, $floorDepth ?? 0, $storeTake, $floorRaw, $error);
    }

    private function refusal(
        ?int $basis,
        int|false|null $days,
        string $timeZone,
        ?int $width,
        ?int $depth,
        ?int $floorWidth,
        ?int $floorDepth,
    ): ?string {
        if ($basis === null) {
            return 'store take is a percent from 0 to 100';
        }
        if ($days === false) {
            return 'days on the floor is a whole number from 1 to 365, or empty';
        }
        if ($width === null || $depth === null) {
            return 'booth size is whole feet';
        }
        if ($floorWidth === null || $floorDepth === null) {
            return 'floor size is whole feet';
        }

        return $this->store($timeZone, $width, $depth, $floorWidth, $floorDepth, $basis, $days);
    }

    private function store(
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        int $storeTake,
        ?int $floorDays,
    ): ?string {
        try {
            $this->mall->save(
                $timeZone,
                $width,
                $depth,
                $floorWidth,
                $floorDepth,
                $storeTake,
                $floorDays,
                $this->app->request()->field('cash_round_nickel') === '1' ? '1' : ''
            );
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

    private function wholeDays(string $value): int|false|null
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,2}$/', $value) !== 1) {
            return false;
        }
        $days = (int) $value;
        if ($days > 365) {
            return false;
        }

        return $days;
    }

    private function dayText(?int $days): string
    {
        return $days === null ? '' : (string) $days;
    }

    private function basisPoints(string $percent): ?int
    {
        if ($percent === '') {
            return 0;
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $percent, $parts) !== 1) {
            return null;
        }
        $fraction = str_pad($parts[2] ?? '', 2, '0');
        $basis = ((int) $parts[1] * 100) + (int) $fraction;
        if ($basis > 10000) {
            return null;
        }

        return $basis;
    }

    private function percent(int $basis): string
    {
        $whole = intdiv($basis, 100);
        $fraction = $basis % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }
        if ($fraction % 10 === 0) {
            return $whole . '.' . (string) intdiv($fraction, 10);
        }

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    private function form(
        string $slug,
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        string $storeTake,
        string $floorDays,
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
            'storeTake' => $storeTake,
            'floorDays' => $floorDays,
            'cashRound' => $this->postedCashRound ?? ($this->mall->cashRoundNickel() ? '1' : ''),
            'backupHeadline' => BackupHeadline::text(
                $this->backups->latest($tenant->id),
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            ),
            'error' => $error,
        ]);
    }
}
