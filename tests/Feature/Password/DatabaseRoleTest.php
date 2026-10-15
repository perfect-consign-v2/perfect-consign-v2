<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseRoleTest extends TestCase
{
    public function testARoleNameThatIsNotAnIdentifierIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('exec');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mall role is not usable');
        (new DatabaseRole($pdo))->assume('1bad');
    }

    public function testARoleThatCannotBeAssumedIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('exec')->with('SET ROLE stall_poster')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mall role could not be used');
        (new DatabaseRole($pdo))->assume('stall_poster');
    }

    public function testARoleThatCannotBeResetIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('exec')->with('RESET ROLE')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the database role could not be reset');
        (new DatabaseRole($pdo))->reset();
    }

    public function testAUsableRoleIsAssumedAndReset(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('exec')->willReturn(0);
        $roles = new DatabaseRole($pdo);
        $roles->assume('stall_poster');
        $roles->reset();
    }
}
