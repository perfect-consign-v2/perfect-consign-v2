<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use PerfectConsign\Feature\Dev\OwnerCredentialPlant;
use PerfectConsign\Feature\Dev\OwnerPlantOutcome;
use PerfectConsign\Feature\Password\PasswordChoice;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OwnerCredentialPlantCoverageTest extends TestCase
{
    public function testSlugAndUsernameAreRequired(): void
    {
        $plant = new OwnerCredentialPlant($this->plantPdo(false));
        foreach ([[' ', 'owner'], ['zcov', '']] as $pair) {
            try {
                $plant->plant(trim($pair[0]), $pair[1], 'correct-horse', null);
                self::fail('an empty slug or username was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('slug and username are required', $error->getMessage());
            }
        }
    }

    public function testAnEmailThatIsNotAnAddressIsRefused(): void
    {
        $plant = new OwnerCredentialPlant($this->plantPdo(false));
        foreach (['not-an-email', str_repeat('a', 190) . '@example.test'] as $email) {
            try {
                $plant->plant('zcov', 'owner', 'correct-horse', '  ' . $email . '  ');
                self::fail('a bad owner email was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('owner email is not an email address', $error->getMessage());
            }
        }
    }

    public function testABlankEmailAndAMissingOwnerInsertANewRow(): void
    {
        $plant = new OwnerCredentialPlant($this->plantPdo(false));
        self::assertSame(OwnerPlantOutcome::Saved, $plant->plant('zcov', 'owner', 'correct-horse', '   '));
        self::assertSame(OwnerPlantOutcome::Saved, $plant->plant('zcov', 'owner', 'correct-horse', null));
    }

    public function testANonRowAndAnInvalidRowAreRefused(): void
    {
        $missing = new OwnerCredentialPlant($this->plantPdo('nope'));
        self::assertSame(OwnerPlantOutcome::Saved, $missing->plant('zcov', 'owner', 'correct-horse', 'owner@example.test'));

        $invalid = new OwnerCredentialPlant($this->plantPdo([
            'id' => '',
            'username' => 'owner',
            'password_hash' => 'hash',
        ]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the owner row is invalid');
        $invalid->plant('zcov', 'owner', 'correct-horse', null);
    }

    public function testAMatchingPasswordLeavesTheProfileOrSavesANewName(): void
    {
        $hash = (new PasswordChoice())->hash('correct-horse');
        $same = new OwnerCredentialPlant($this->plantPdo([
            'id' => '01900000-0000-7000-8000-0000000000aa',
            'username' => 'owner',
            'password_hash' => $hash,
            'email' => 'Owner@Example.TEST',
        ]));
        self::assertSame(
            OwnerPlantOutcome::Unchanged,
            $same->plant('zcov', 'owner', 'correct-horse', null),
        );
        self::assertSame(
            OwnerPlantOutcome::Unchanged,
            $same->plant('zcov', 'owner', 'correct-horse', 'owner@example.test'),
        );

        $renamed = new OwnerCredentialPlant($this->plantPdo([
            'id' => '01900000-0000-7000-8000-0000000000aa',
            'username' => 'owner',
            'password_hash' => $hash,
            'email' => 4,
        ]));
        self::assertSame(
            OwnerPlantOutcome::ProfileSaved,
            $renamed->plant('zcov', 'desk', 'correct-horse', 'desk@example.test'),
        );

        $emptyHash = new OwnerCredentialPlant($this->plantPdo([
            'id' => '01900000-0000-7000-8000-0000000000aa',
            'username' => 'owner',
            'password_hash' => '',
            'email' => null,
        ]));
        self::assertSame(OwnerPlantOutcome::Saved, $emptyHash->plant('zcov', 'owner', 'correct-horse', null));
    }

    public function testADuplicateEmailIsRefusedAndAnyOtherDatabaseErrorEscapes(): void
    {
        $duplicate = new PDOException('duplicate');
        $duplicate->errorInfo = ['23505', null, 'duplicate'];
        $plant = new OwnerCredentialPlant($this->plantPdo(false, 1, $duplicate));
        try {
            $plant->plant('zcov', 'owner', 'correct-horse', 'owner@example.test');
            self::fail('a duplicate email was stored');
        } catch (RuntimeException $error) {
            self::assertSame('that email is already used on another sign-in', $error->getMessage());
        }

        $hash = (new PasswordChoice())->hash('correct-horse');
        $touch = new OwnerCredentialPlant($this->plantPdo([
            'id' => '01900000-0000-7000-8000-0000000000aa',
            'username' => 'owner',
            'password_hash' => $hash,
            'email' => 'other@example.test',
        ], 1, $duplicate));
        try {
            $touch->plant('zcov', 'owner', 'correct-horse', 'owner@example.test');
            self::fail('a duplicate email was saved on the profile');
        } catch (RuntimeException $error) {
            self::assertSame('that email is already used on another sign-in', $error->getMessage());
        }

        $other = new PDOException('locked');
        $other->errorInfo = [];
        $again = new OwnerCredentialPlant($this->plantPdo(false, 1, $other));
        $this->expectException(PDOException::class);
        $again->plant('zcov', 'owner', 'correct-horse', null);
    }

    public function testAMissingMallIsRefused(): void
    {
        $plant = new OwnerCredentialPlant($this->plantPdo(false, 0));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('that mall was not found');
        $plant->plant('zcov', 'owner', 'correct-horse', null);
    }

    private function plantPdo(mixed $existing, int $rowCount = 1, ?PDOException $error = null): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($existing, $rowCount, $error): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('rowCount')->willReturn($rowCount);
            $statement->method('fetch')->willReturn($existing);
            if (!str_starts_with(ltrim($sql), 'SELECT') && $error instanceof PDOException) {
                $statement->method('execute')->willThrowException($error);

                return $statement;
            }
            $statement->method('execute')->willReturn(true);

            return $statement;
        });

        return $pdo;
    }
}
