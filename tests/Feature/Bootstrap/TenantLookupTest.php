<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PDO;
use PerfectConsign\Bootstrap\TenantLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantLookupTest extends TestCase
{
    public function testARoleResetThatFailsIsRefused(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the database role could not be reset');
        (new TenantLookup($pdo))->find('demo');
    }

    public function testABadSlugIsMissing(): void
    {
        self::assertNull((new TenantLookup($this->createStub(PDO::class)))->find('Bad Slug'));
    }
}
