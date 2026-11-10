<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\MirrorProver;
use PerfectConsign\Feature\Pc1Mirror\MirrorTarget;
use PerfectConsign\Feature\Pc1Mirror\Pc1RentOpen;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Pc1MirrorObligationNamesTest extends TestCase
{
    public function testPc1MirrorHelperMethodsExist(): void
    {
        $prover = new ReflectionClass(MirrorProver::class);
        self::assertTrue($prover->hasMethod('sumRole'));
        self::assertTrue($prover->hasMethod('sumAccount'));

        $target = new ReflectionClass(MirrorTarget::class);
        self::assertTrue($target->hasMethod('assertV2Host'));
        self::assertTrue($target->hasMethod('hostAllowed'));
        self::assertTrue($target->hasMethod('requireEnv'));

        $rent = new ReflectionClass(Pc1RentOpen::class);
        self::assertTrue($rent->hasMethod('prorate'));
    }
}
