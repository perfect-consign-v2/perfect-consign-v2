<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminTenantWipe;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Tenant\TenantRecord;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminTenantWipeCoverageTest extends TestCase
{
    private const ACTING_ID = '01900000-0000-7000-8000-0000000000aa';

    private const SECOND_ID = '01900000-0000-7000-8000-0000000000bb';

    private const TENANT_ID = '01900000-0000-7000-8000-0000000000cc';

    private const SECOND_EMAIL = 'second@example.test';

    public function testTheSlugMustMatchBeforeAnyoneElseIsAsked(): void
    {
        $outcomes = [];
        $details = [];
        $wipe = $this->wipe($this->pdo(false, false, false, null, $outcomes, $details));
        try {
            $wipe->wipe($this->admin(), $this->tenant(), 'other-slug', '', '');
            self::fail('a mismatched slug was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Type the store slug exactly to confirm deletion.', $error->getMessage());
        }
        self::assertSame(['refused'], $outcomes);
    }

    public function testASecondAdminIsRefusedWhenTheSignatureIsUnusable(): void
    {
        $long = str_repeat('a', 190) . '@example.test';
        foreach (['not-an-email', $long, self::SECOND_EMAIL] as $email) {
            $password = $email === self::SECOND_EMAIL ? '' : 'Second-Pass-1';
            $outcomes = [];
            $details = [];
            $wipe = $this->wipe($this->pdo(false, false, false, null, $outcomes, $details));
            try {
                $wipe->wipe($this->admin(), $this->tenant(), 'zcov-wipe', $email, $password);
                self::fail('an unusable second signature was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('A second platform admin must sign this deletion.', $error->getMessage());
            }
            self::assertSame(['refused'], $outcomes);
        }
    }

    public function testASecondAdminIsRefusedWhenTheAccountCannotSign(): void
    {
        $cases = [
            false,
            ['id' => 4, 'email' => self::SECOND_EMAIL, 'password_hash' => 'hash'],
            ['id' => self::ACTING_ID, 'email' => self::SECOND_EMAIL, 'password_hash' => $this->hash()],
            ['id' => self::SECOND_ID, 'email' => self::SECOND_EMAIL, 'password_hash' => $this->hash()],
        ];
        foreach ($cases as $index => $row) {
            $password = $index === 3 ? 'wrong-password' : 'Second-Pass-1';
            $outcomes = [];
            $details = [];
            $wipe = $this->wipe($this->pdo($row, false, false, null, $outcomes, $details));
            try {
                $wipe->wipe($this->admin(), $this->tenant(), 'zcov-wipe', '  Second@Example.TEST ', $password);
                self::fail('a second admin who cannot sign was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('A second platform admin must sign this deletion.', $error->getMessage());
            }
            self::assertSame(['refused'], $outcomes);
        }
    }

    public function testDeletionStopsWhenTheBackupIsMissingOrOld(): void
    {
        foreach ([false, (new DateTimeImmutable('-30 days'))->format(DATE_ATOM)] as $backup) {
            $outcomes = [];
            $details = [];
            $wipe = $this->wipe($this->pdo($this->secondRow(), $backup, false, null, $outcomes, $details));
            try {
                $wipe->wipe($this->admin(), $this->tenant(), 'zcov-wipe', self::SECOND_EMAIL, 'Second-Pass-1');
                self::fail('a store without a recent backup was deleted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('seven days', $error->getMessage());
            }
            self::assertSame(['refused'], $outcomes);
        }
    }

    public function testDeletionStopsWhilePasswordResetsRemain(): void
    {
        $outcomes = [];
        $details = [];
        $wipe = $this->wipe($this->pdo($this->secondRow(), (new DateTimeImmutable())->format(DATE_ATOM), '1', null, $outcomes, $details));
        try {
            $wipe->wipe($this->admin(), $this->tenant(), 'zcov-wipe', self::SECOND_EMAIL, 'Second-Pass-1');
            self::fail('a store with password resets was deleted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('password reset', $error->getMessage());
        }
        self::assertSame(['refused'], $outcomes);
    }

    public function testADatabaseFailureWhileDroppingIsAudited(): void
    {
        $outcomes = [];
        $details = [];
        $failure = new PDOException('wipe failed');
        $failure->errorInfo = ['P0001', null, 'wipe failed'];
        $wipe = $this->wipe($this->pdo(
            $this->secondRow(),
            (new DateTimeImmutable())->format(DATE_ATOM),
            false,
            $failure,
            $outcomes,
            $details,
        ));
        try {
            $wipe->wipe($this->admin(), $this->tenant(), 'zcov-wipe', self::SECOND_EMAIL, 'Second-Pass-1');
            self::fail('a failed drop was reported as success');
        } catch (RuntimeException $error) {
            self::assertSame('wipe failed', $error->getMessage());
            self::assertSame($failure, $error->getPrevious());
        }
        self::assertSame(['failed'], $outcomes);
    }

    public function testASignedDeletionRecordsSuccessWithoutCallingTheLiveDatabase(): void
    {
        $outcomes = [];
        $details = [];
        $wipe = $this->wipe($this->pdo(
            $this->secondRow(),
            (new DateTimeImmutable())->format(DATE_ATOM),
            false,
            null,
            $outcomes,
            $details,
        ));
        $wipe->wipe($this->admin(), $this->tenant(), 'zcov-wipe', '  Second@Example.TEST ', 'Second-Pass-1');
        self::assertSame(['ok'], $outcomes);
        self::assertNotSame([], $details);
        $encoded = $details[0];
        self::assertIsString($encoded);
        self::assertStringContainsString(self::SECOND_EMAIL, $encoded);
    }

    /**
     * @param array<string, mixed>|false $adminRow
     * @param list<string> $outcomes
     * @param list<string> $details
     */
    private function pdo(
        array|false $adminRow,
        mixed $backup,
        mixed $reset,
        ?PDOException $wipeError,
        array &$outcomes,
        array &$details,
    ): PDO {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (
            $adminRow,
            $backup,
            $reset,
            $wipeError,
            &$outcomes,
            &$details,
        ): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('rowCount')->willReturn(1);
            if (str_contains($sql, 'platform.admins')) {
                $statement->method('execute')->willReturn(true);
                $statement->method('fetch')->willReturn($adminRow);

                return $statement;
            }
            if (str_contains($sql, 'latest_owner_backup')) {
                $statement->method('execute')->willReturn(true);
                $statement->method('fetchColumn')->willReturn($backup);

                return $statement;
            }
            if (str_contains($sql, 'password_resets')) {
                $statement->method('execute')->willReturn(true);
                $statement->method('fetchColumn')->willReturn($reset);

                return $statement;
            }
            if (str_contains($sql, 'wipe_tenant')) {
                if ($wipeError instanceof PDOException) {
                    $statement->method('execute')->willThrowException($wipeError);
                } else {
                    $statement->method('execute')->willReturn(true);
                }

                return $statement;
            }
            $statement->method('execute')->willReturnCallback(function (array $params) use (&$outcomes, &$details): bool {
                $outcome = $params[3] ?? null;
                if (is_string($outcome)) {
                    $outcomes[] = $outcome;
                }
                $detail = $params[6] ?? null;
                if (is_string($detail)) {
                    $details[] = $detail;
                }

                return true;
            });

            return $statement;
        });

        return $pdo;
    }

    private function wipe(PDO $pdo): AdminTenantWipe
    {
        return new AdminTenantWipe(
            $pdo,
            new AdminAudit($pdo, new Request('POST', '/admin/tenants/zcov-wipe/delete', [], [], '', '', '127.0.0.1')),
            new BackupStore($pdo),
        );
    }

    /**
     * @return array{id: string, email: string, version: string}
     */
    private function admin(): array
    {
        return ['id' => self::ACTING_ID, 'email' => 'acting@example.test', 'version' => '1'];
    }

    private function tenant(): TenantRecord
    {
        return new TenantRecord(
            self::TENANT_ID,
            'zcov-wipe',
            'tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'poster_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'Zcov Wipe',
        );
    }

    /**
     * @return array{id: string, email: string, password_hash: string}
     */
    private function secondRow(): array
    {
        return [
            'id' => self::SECOND_ID,
            'email' => 'Second@Example.TEST',
            'password_hash' => $this->hash(),
        ];
    }

    private function hash(): string
    {
        return (new PasswordChoice())->hash('Second-Pass-1');
    }
}
