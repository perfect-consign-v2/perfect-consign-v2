<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Opening;

use PDO;
use PerfectConsign\Feature\Opening\OpeningStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpeningStoreTest extends TestCase
{
    public function testQueryFailureThrowsInsteadOfDenyingOpening(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('opening allowance query failed');
        (new OpeningStore($pdo))->openingAllowed();
    }
}
