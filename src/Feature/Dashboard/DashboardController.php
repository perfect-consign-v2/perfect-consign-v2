<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Jobs\JobQueue;
use PDO;

final class DashboardController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly DashboardStore $dashboard,
        private readonly MallSettings $mall,
        private readonly RentDue $rentDue,
        private readonly JobQueue $jobs,
        private readonly PDO $pdo,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/dashboard', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone($this->mall->timeZone())))->format('G');
        $part = 'evening';
        if ($hour < 12) {
            $part = 'morning';
        } elseif ($hour < 17) {
            $part = 'afternoon';
        }
        $due = $this->rentDue->open();
        $this->app->show('Feature/Dashboard/dashboard', [
            'title' => 'Dashboard',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'greeting' => 'Good ' . $part . ', ' . $tenant->displayName,
            'picture' => $this->dashboard->picture($today),
            'rentRows' => $due['rows'],
            'rentMonth' => $due['month'],
            'overdueJobs' => $this->jobs->overdue($this->pdo),
        ]);
    }
}
