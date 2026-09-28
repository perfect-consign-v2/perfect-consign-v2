<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;

final class RegisterGate
{
    public function __construct(
        private readonly MallApp $app,
        private readonly ActiveRegister $drawers,
    ) {
    }

    public function session(string $slug): string
    {
        $session = $this->drawers->current($slug, true);
        if ($session === '') {
            $this->app->redirect(
                '/' . $slug . '/registers',
                'Open a register and make it active before the sale screen.'
            );
        }

        return $session;
    }
}
