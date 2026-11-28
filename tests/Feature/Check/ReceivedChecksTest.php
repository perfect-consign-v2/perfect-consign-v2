<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Check;

use PerfectConsign\Feature\Check\ReceivedChecks;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class ReceivedChecksTest extends TestCase
{
    private const JOURNAL = '01900000-0000-7000-8000-0000000000j1';

    public function testANumberFindsTheChecksTheDatabaseListsAndABlankFindsNone(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects(self::once())->method('bound')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'FROM stall_received_checks(?)')),
            ['1042'],
        )->willReturn([[
            'journal_id' => self::JOURNAL,
            'check_number' => '1042',
            'received_on' => '2026-11-20',
            'amount_minor' => '1234',
            'said' => 'Gift certificate',
            'bounced' => 'f',
        ]]);
        $checks = new ReceivedChecks($statements, $this->createStub(PostingFunctions::class), QuietAudit::owner($statements));

        self::assertSame([], $checks->find(''));
        self::assertSame([[
            'journal_id' => self::JOURNAL,
            'check_number' => '1042',
            'received_on' => '2026-11-20',
            'amount_minor' => 1234,
            'said' => 'Gift certificate',
            'bounced' => false,
        ]], $checks->find('1042'));
    }

    public function testBounceAsksTheDatabaseToPostAndAuditsIt(): void
    {
        $statements = $this->createStub(Statements::class);
        $audits = 0;
        $statements->method('affected')->willReturnCallback(static function () use (&$audits): int {
            $audits++;

            return 1;
        });
        $postings = $this->createMock(PostingFunctions::class);
        $postings->expects(self::once())->method('post')->willReturnCallback(
            static function (string $date, string $call, array $values, ?string $session = null, ?string $check = null, ?int $tendered = null, ?callable $beforeCommit = null): string {
                self::assertSame('2026-11-27', $date);
                self::assertSame('stall_return_check(?::uuid, ?::date)', $call);
                self::assertSame([self::JOURNAL, '2026-11-27'], $values);
                self::assertNotNull($beforeCommit);
                $beforeCommit('posted');

                return 'posted';
            },
        );

        self::assertSame('posted', (new ReceivedChecks($statements, $postings, QuietAudit::owner($statements)))->bounce(self::JOURNAL, '2026-11-27'));
        self::assertSame(1, $audits);
    }
}
