<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Shared\Http\ResponseSent;

trait CapturesPage
{
    private function capture(callable $run): string
    {
        ob_start();
        try {
            $run();
        } catch (ResponseSent) {
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
