<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\PosRegister;
use PerfectConsign\Feature\Pos\SnapSync;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class SnapAuthorizeTest extends TestCase
{
    public function testMintReturnsPostingKeysFromTheTicket(): void
    {
        $keys = (new SnapSync($this->mintStatements('session-1', 'ticket-1', [
            ['posting_key' => '01900000-0000-7000-8000-00000000aa01'],
            ['posting_key' => ''],
        ])))->mint('session-1', 'ticket-1');

        self::assertSame(['01900000-0000-7000-8000-00000000aa01'], $keys);
    }

    public function testSnapKeysMintsWhenTheTicketHasLines(): void
    {
        $keys = $this->register($this->mintStatements('session-1', 'ticket-1', [
            ['posting_key' => '01900000-0000-7000-8000-00000000aa02'],
        ]))->snapKeys('session-1', [
            'ticket_id' => 'ticket-1',
            'lines' => [['line_no' => 1]],
        ]);

        self::assertSame(['01900000-0000-7000-8000-00000000aa02'], $keys);
    }

    public function testSnapKeysStayEmptyWithoutLines(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects(self::never())->method('bound');

        self::assertSame([], $this->register($statements)->snapKeys('session-1', [
            'ticket_id' => 'ticket-1',
            'lines' => [],
        ]));
    }

    public function testSnapKeysPropagateWhenMintIsRefused(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects(self::once())
            ->method('bound')
            ->willThrowException(new DatabaseRefusal('there is no open ticket'));

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('there is no open ticket');
        $this->register($statements)->snapKeys('session-1', [
            'ticket_id' => 'ticket-1',
            'lines' => [['line_no' => 1]],
        ]);
    }

    /**
     * @param list<array{posting_key: string}> $rows
     */
    private function mintStatements(string $session, string $ticket, array $rows): Statements
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects(self::once())
            ->method('bound')
            ->with(
                'SELECT stall_mint_register_snaps(?::uuid, ?::uuid) AS posting_key',
                [$session, $ticket]
            )
            ->willReturn($rows);

        return $statements;
    }

    private function register(Statements $statements): PosRegister
    {
        $register = (new ReflectionClass(PosRegister::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PosRegister::class, 'snaps'))->setValue($register, new SnapSync($statements));

        return $register;
    }
}
