<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Shared\Auth\OwnerAccess;
use PHPUnit\Framework\TestCase;

final class OwnerAccessTest extends TestCase
{
    public function testASignedInOwnerOfAnotherMallIsForeign(): void
    {
        $access = new OwnerAccess();

        self::assertTrue($access->isForeign('mall', 'other'));
    }

    public function testTheSameMallIsNotForeign(): void
    {
        $access = new OwnerAccess();

        self::assertFalse($access->isForeign('mall', 'mall'));
    }

    public function testAMissingSessionIsNotForeign(): void
    {
        $access = new OwnerAccess();

        self::assertFalse($access->isForeign(null, 'mall'));
    }
}
