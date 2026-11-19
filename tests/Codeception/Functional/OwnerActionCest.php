<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Codeception\Support\OwnerActionSweep;
use PerfectConsign\Tests\Codeception\Support\OwnerRouteAudit;
use PerfectConsign\Tests\Support\ProbeMall;

/**
 * An owner exercises every mall route. A new route fails here until the sweep requests it.
 */
final class OwnerActionCest
{
    public function ownerExercisesEveryMallRoute(FunctionalTester $I): void
    {
        $sweep = new OwnerActionSweep($I->probeClient(), ProbeMall::SLUG);
        $result = $sweep->run();
        $problems = $result['failures'];
        foreach (OwnerRouteAudit::missed($result['hits']) as $route) {
            $problems[] = 'never requested: ' . $route;
        }
        $I->assertSame([], $problems, implode("\n", $problems));
    }
}
