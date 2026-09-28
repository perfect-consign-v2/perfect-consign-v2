<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;

final class RegisterGate
{
    public function __construct(private readonly MallApp $app)
    {
    }

    public function session(string $slug): string
    {
        $session = $this->app->drawerSession($slug);
        if ($session === '') {
            $this->app->redirect(
                '/' . $slug . '/registers',
                'Open a register and make it active before the sale screen.'
            );
        }

        return $session;
    }
}
