<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Feature\Vendor\VendorEntry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class VendorEntryGapTest extends TestCase
{
    private const SCHEMA = 'tenant_abababababababababababababababab';

    private const VENDOR = '01900000-0000-7000-8000-0000000000c1';

    public function testAMissingMailboxIsLoggedAndNoMailIsFiled(): void
    {
        $messages = [];
        $this->entry($this->counts(0, 0), false, $messages, true)->request(
            $this->tenant(self::SCHEMA),
            'Ada@Example.test',
            'https://mall.example',
        );

        self::assertContains('consignor entry mailbox is not set up', $messages);
    }

    public function testAFilingFailureIsLogged(): void
    {
        $messages = [];
        $this->entry($this->counts(0, 0), true, $messages, false)->request(
            $this->tenant(self::SCHEMA),
            'ada@example.test',
            'https://mall.example',
        );

        self::assertContains('consignor entry mail could not be filed', $messages);
    }

    public function testEmailAndClientFloodsMintNothing(): void
    {
        $messages = [];
        $this->entry($this->counts(5, 0), true, $messages, true)->request(
            $this->tenant(self::SCHEMA),
            'ada@example.test',
            'https://mall.example',
        );
        $this->entry($this->counts(0, 40), true, $messages, true)->request(
            $this->tenant(self::SCHEMA),
            'ada@example.test',
            'https://mall.example',
        );

        self::assertSame([], $messages);
    }

    public function testACountFailureRollsTheLockBack(): void
    {
        $messages = [];
        $rolled = false;
        try {
            $this->entry($this->counts(0, 0), true, $messages, true, true, $rolled)->request(
                $this->tenant(self::SCHEMA),
                'ada@example.test',
                'https://mall.example',
            );
            self::fail('a count failure was swallowed');
        } catch (RuntimeException $error) {
            self::assertSame('count failed', $error->getMessage());
        }

        self::assertTrue($rolled);
    }

    public function testABadSchemaIsRefused(): void
    {
        $messages = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall schema is not usable');
        $this->entry($this->counts(0, 0), true, $messages, true)->request(
            $this->tenant('public'),
            'ada@example.test',
            'https://mall.example',
        );
    }

    public function testASearchPathFailureIsRefused(): void
    {
        $messages = [];
        $rolled = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall schema could not be used');
        $this->entry($this->counts(0, 0), true, $messages, true, false, $rolled, false)->request(
            $this->tenant(self::SCHEMA),
            'ada@example.test',
            'https://mall.example',
        );
    }

    /**
     * @param array{email: int, client: int} $counts
     * @param list<string> $messages
     */
    private function entry(
        array $counts,
        bool $ready,
        array &$messages,
        bool $fileId,
        bool $countThrows = false,
        bool &$rolled = false,
        bool $searchPath = true,
    ): VendorEntry {
        $open = false;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open, &$rolled): bool {
            $open = false;
            $rolled = true;

            return true;
        });
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use ($searchPath): int|false {
            if (!$searchPath && str_starts_with($sql, 'SET search_path')) {
                return false;
            }

            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function () use ($fileId): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn($fileId ? self::VENDOR : false);
            $statement->method('fetch')->willReturn(false);

            return $statement;
        });
        $statements = $this->createStub(Statements::class);
        $statements->method('change')->willReturnCallback(static function (): void {
        });
        $statements->method('bound')->willReturnCallback(
            static function (string $sql) use ($counts, $countThrows): array {
                if ($countThrows && str_contains($sql, 'count(*)')) {
                    throw new RuntimeException('count failed');
                }
                if (str_contains($sql, 'stall_party_primary_email')) {
                    return [['id' => self::VENDOR]];
                }
                if (str_contains($sql, 'client_address')) {
                    return [['requests' => $counts['client']]];
                }
                if (str_contains($sql, 'count(*)')) {
                    return [['requests' => $counts['email']]];
                }

                return [];
            }
        );
        $mailbox = $this->createStub(LetterMailbox::class);
        $mailbox->method('ready')->willReturn($ready);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return new VendorEntry(
            $pdo,
            new DatabaseRole($pdo),
            $statements,
            $mailbox,
            new MailOutbox($pdo),
            $logger,
            new Request('POST', '/gapmall/consignor', [], [], '', '', '203.0.113.8'),
        );
    }

    private function tenant(string $schema): TenantRecord
    {
        return new TenantRecord(
            '01900000-0000-7000-8000-0000000000bb',
            'gapmall',
            $schema,
            'poster_abababababababababababababababab',
            'Gap',
        );
    }

    /**
     * @return array{email: int, client: int}
     */
    private function counts(int $email, int $client): array
    {
        return ['email' => $email, 'client' => $client];
    }
}
