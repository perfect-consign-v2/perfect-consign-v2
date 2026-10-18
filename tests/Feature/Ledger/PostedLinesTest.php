<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Ledger;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class PostedLinesTest extends TestCase
{
    public function testAPartialLineAndABadSubledgerAreRefused(): void
    {
        $request = new Request('POST', '/adjustment', [], [
            'line_account_1' => '01900000-0000-7000-8000-000000000001',
            'line_amount_1' => '1.00',
            'line_side_1' => 'debit',
            'line_account_2' => '01900000-0000-7000-8000-000000000002',
            'line_amount_2' => '',
            'line_side_2' => 'credit',
        ]);
        try {
            PostedLines::read($request, 2, false);
            self::fail('a partial line was posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('each line needs an account and an amount', $error->getMessage());
        }

        $side = new Request('POST', '/adjustment', [], [
            'line_account_1' => '01900000-0000-7000-8000-000000000001',
            'line_amount_1' => '1.00',
            'line_side_1' => 'neither',
            'line_account_2' => '01900000-0000-7000-8000-000000000002',
            'line_amount_2' => '1.00',
            'line_side_2' => 'credit',
        ]);
        try {
            PostedLines::read($side, 2, false);
            self::fail('a line without a side was posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('each line is a debit or a credit', $error->getMessage());
        }

        $subledger = new Request('POST', '/adjustment', [], [
            'line_account_1' => '01900000-0000-7000-8000-000000000001',
            'line_amount_1' => '1.00',
            'line_side_1' => 'debit',
            'line_subledger_type_1' => '',
            'line_subledger_ref_1' => '',
            'line_account_2' => '01900000-0000-7000-8000-000000000002',
            'line_amount_2' => '1.00',
            'line_side_2' => 'credit',
            'line_subledger_type_2' => 'drawer',
            'line_subledger_ref_2' => 'x',
        ]);
        try {
            PostedLines::read($subledger, 2, true);
            self::fail('a drawer subledger was posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a subledger is a vendor or a booth', $error->getMessage());
        }
    }
}
