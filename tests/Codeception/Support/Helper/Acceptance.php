<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support\Helper;

use Codeception\Module;
use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;

final class Acceptance extends Module
{
    public function mallSlug(): string
    {
        return ProbeMall::SLUG;
    }

    public function probeSeed(): ProbeSurfaceSeed
    {
        return ProbeHarness::require()->seed();
    }
}
