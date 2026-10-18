<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Book;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Book\JournalController;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class JournalControllerTest extends TestCase
{
    public function testACloseOrAReversalCannotBeVoided(): void
    {
        $controller = (new ReflectionClass(JournalController::class))->newInstanceWithoutConstructor();
        $canVoid = new ReflectionMethod(JournalController::class, 'canVoid');
        self::assertFalse($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => false], true));
        self::assertFalse($canVoid->invoke($controller, ['source_type' => 'period_close', 'is_reversal' => false], false));
        self::assertFalse($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => true], false));
        self::assertFalse($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => 't'], false));
        self::assertFalse($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => '1'], false));
        self::assertFalse($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => 1], false));
        self::assertTrue($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => false], false));
        self::assertTrue($canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => 'f'], false));
    }

    public function testVoidLinesSwapDebitAndCredit(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('lines')->willReturn([[
            'account_id' => 'acct-1',
            'debit_minor' => 10,
            'credit_minor' => 0,
            'subledger_type' => 'vendor',
            'subledger_ref' => 'v1',
        ]]);
        $controller = (new ReflectionClass(JournalController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(JournalController::class, 'ledger'))->setValue($controller, $ledger);
        $swapped = (new ReflectionMethod(JournalController::class, 'swappedLines'))->invoke($controller, 'j1');
        self::assertSame([[
            'account_id' => 'acct-1',
            'debit' => 0,
            'credit' => 10,
            'subledger_type' => 'vendor',
            'subledger_ref' => 'v1',
        ]], $swapped);
    }

    public function testThePostedDateIsUsedWhenTheFormOmitsOne(): void
    {
        $app = (new ReflectionClass(MallApp::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(MallApp::class, 'request'))->setValue($app, new Request('POST', '/journals', [], [
            'posting_date' => '',
        ]));
        $controller = (new ReflectionClass(JournalController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(JournalController::class, 'app'))->setValue($controller, $app);
        $date = (new ReflectionMethod(JournalController::class, 'postingDate'))->invoke($controller, [
            'posting_date' => '2026-10-01',
        ]);
        self::assertSame('2026-10-01', $date);
        $second = (new ReflectionClass(MallApp::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(MallApp::class, 'request'))->setValue($second, new Request('POST', '/journals', [], [
            'posting_date' => '2026-10-02',
        ]));
        $later = (new ReflectionClass(JournalController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(JournalController::class, 'app'))->setValue($later, $second);
        self::assertSame('2026-10-02', (new ReflectionMethod(JournalController::class, 'postingDate'))->invoke($later, [
            'posting_date' => '2026-10-01',
        ]));
    }
}
