<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;

final class RegisterHub
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterStore $registers,
        private readonly ActiveRegister $drawers,
    ) {
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function show(
        string $slug,
        ?string $error,
        string $modal,
        string $registerId,
        string $confirm,
        array $draft = [],
    ): void {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Register/registers', [
            'title' => 'Registers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'registers' => $this->registers->cards(),
            'cashier' => $this->drawers->ownerName(),
            'denominations' => DenominationCount::LABELS,
            'modal' => $modal,
            'registerId' => $registerId,
            'confirmVariance' => $confirm === 'yes',
            'draft' => $draft,
            'error' => $error,
        ]);
    }
}
