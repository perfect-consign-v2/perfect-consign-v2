<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\DraftAwareDiscard;
use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class PosDiscardTest extends TestCase
{
    public function testDraftsOnlyNoTicketStillSucceedsAndClearsDrafts(): void
    {
        $draftStatements = $this->createMock(Statements::class);
        $draftStatements->expects($this->once())
            ->method('bound')
            ->willReturn([['present' => 1]]);
        $draftStatements->expects($this->once())
            ->method('change')
            ->with(
                'DELETE FROM ticket_draft_lines WHERE session_id = ?::uuid',
                ['01900000-0000-7000-8000-00000000e001']
            );
        $ticketStatements = $this->createMock(Statements::class);
        $ticketStatements->expects($this->once())
            ->method('affected')
            ->willReturn(0);

        (new DraftAwareDiscard(
            $this->tickets($ticketStatements),
            new TicketDrafts($draftStatements),
        ))->run('01900000-0000-7000-8000-00000000e001');
    }

    public function testOtherRefusalIsNotSwallowedWhenDraftsExist(): void
    {
        $draftStatements = $this->createMock(Statements::class);
        $draftStatements->expects($this->once())
            ->method('bound')
            ->willReturn([['present' => 1]]);
        $draftStatements->expects($this->never())->method('change');
        $ticketStatements = $this->createMock(Statements::class);
        $ticketStatements->expects($this->once())
            ->method('affected')
            ->willThrowException(new DatabaseRefusal('ticket is already sold'));

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('ticket is already sold');
        (new DraftAwareDiscard(
            $this->tickets($ticketStatements),
            new TicketDrafts($draftStatements),
        ))->run('01900000-0000-7000-8000-00000000e001');
    }

    private function tickets(Statements $statements): TicketStore
    {
        $store = (new ReflectionClass(TicketStore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TicketStore::class, 'statements'))->setValue($store, $statements);

        return $store;
    }
}
