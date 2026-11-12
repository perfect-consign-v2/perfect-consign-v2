<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use PDO;
use PDOException;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PlatformSettingsTest extends TestCase
{
    public function testPdoFailureIsLoggedAndRethrown(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('connection lost'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'platform setting read failed',
            $this->callback(static function (array $context): bool {
                return ($context['key'] ?? null) === 'store_signup_enabled'
                    && isset($context['message']);
            })
        );

        $this->expectException(PDOException::class);
        (new PlatformSettings($pdo, $logger))->storeSignupEnabled();
    }

    public function testMissingSettingUsesDefault(): void
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        self::assertFalse(
            (new PlatformSettings($pdo, $this->createStub(LoggerInterface::class)))->storeSignupEnabled()
        );
    }
}
