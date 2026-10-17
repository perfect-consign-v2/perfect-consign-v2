<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;

final class CustomerController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly CustomerDirectory $customers,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $phone = $this->app->request()->query('phone');
        $this->app->show('Feature/Pos/customers', [
            'title' => 'Customers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'phone' => $phone,
            'customers' => $this->customers->listed($phone),
        ]);
    }
}
