<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\TenantDeletePolicy;
use PHPUnit\Framework\TestCase;

final class TenantDeletePolicyTest extends TestCase
{
    public function testOnlyProductionRequiresASecondAdmin(): void
    {
        $policy = new TenantDeletePolicy();
        $previous = getenv('APP_ENV');
        try {
            putenv('APP_ENV');
            self::assertFalse($policy->requiresCountersign());
            foreach (['local', 'development', 'dev', 'test', 'coverage'] as $env) {
                putenv('APP_ENV=' . $env);
                self::assertFalse($policy->requiresCountersign());
            }
            foreach (['production', 'prod', 'Production'] as $env) {
                putenv('APP_ENV=' . $env);
                self::assertTrue($policy->requiresCountersign());
            }
        } finally {
            if ($previous === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previous);
            }
        }
    }
}
