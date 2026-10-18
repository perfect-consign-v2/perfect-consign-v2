<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Feature\Payout\PayoutRunChecks;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class PayoutRunChecksTest extends TestCase
{
    public function testAStartingNumberMustBePositive(): void
    {
        $checks = new PayoutRunChecks($this->createStub(Statements::class));
        try {
            $checks->assignNumbers('00000000-0000-7000-8000-000000000020', 0);
            self::fail('a zero starting check was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Type the number on the first check.', $error->getMessage());
        }
    }

    public function testChecksMapFromTheRunLines(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
            'check_minor' => 1250,
            'mode' => 'pay',
        ]]);
        $rows = (new PayoutRunChecks($statements))->checks('00000000-0000-7000-8000-000000000020');
        self::assertSame(1250, $rows[0]['check_minor'] ?? null);
    }

    public function testNumberingSkipsALineThatIsAlreadyTaken(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            return $work();
        });
        $statements->method('bound')->willReturn([
            [
                'vendor_id' => '00000000-0000-7000-8000-0000000000a1',
                'disbursement_journal_id' => '00000000-0000-7000-8000-0000000000j1',
            ],
            [
                'vendor_id' => '00000000-0000-7000-8000-0000000000a2',
                'disbursement_journal_id' => '00000000-0000-7000-8000-0000000000j2',
            ],
        ]);
        $statements->method('affected')->willReturnOnConsecutiveCalls(0, 1);
        $changed = [];
        $statements->method('change')->willReturnCallback(static function (string $sql, array $params) use (&$changed): void {
            $changed[] = $params;
        });
        (new PayoutRunChecks($statements))->assignNumbers('00000000-0000-7000-8000-000000000020', 40);
        self::assertCount(1, $changed);
        self::assertSame(['00000000-0000-7000-8000-0000000000j2', '40'], $changed[0]);
    }
}
