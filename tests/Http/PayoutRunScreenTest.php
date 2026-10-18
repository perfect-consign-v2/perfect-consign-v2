<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PayoutRunScreenTest extends WebTestCase
{
    public function testThePackPrintsOnePagePerVendor(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $page = $client->get('/' . ProbeMall::SLUG . '/payouts');

        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/[A-Z][a-z]+ payout/', $page->body);
        self::assertSees('Print this list', $page);
        self::assertSees('window.print()', $page);
        self::assertTrue(
            str_contains($page->body, 'Pay these vendors') || str_contains($page->body, ' is paid')
        );
        self::assertStringNotContainsString('vendor-sheet', $page->body);
        self::assertStringNotContainsString('Print the pack', $page->body);
        self::assertStringNotContainsString('Print envelopes', $page->body);
        self::assertTrue(
            str_contains($page->body, 'dt-column-title">Pay them<')
            || str_contains($page->body, 'dt-column-title">Paid them<')
        );
        self::assertSees('They earned', $page);
        self::assertSees('Rent', $page);
        self::assertSees('Rent comes out of what they earned.', $page);
        self::assertSees('Owes the store', $page);
        self::assertStringNotContainsString('First check number', $page->body);
        self::assertStringNotContainsString('Their share', $page->body);
        self::assertStringNotContainsString('Owed before', $page->body);
        self::assertStringNotContainsString('dt-column-title">This month<', $page->body);
        self::assertStringNotContainsString('Paid since', $page->body);
        self::assertSees('—', $page);
        self::assertDoesNotMatchRegularExpression('/name="from"|name="to"|name="scope"|name="minimum"/', $page->body);
        $past = $client->get('/' . ProbeMall::SLUG . '/payouts/past');
        self::assertSame(200, $past->status);
        $report = $client->get('/' . ProbeMall::SLUG . '/payouts/report');
        self::assertSame(200, $report->status);
        $missing = $client->get('/' . ProbeMall::SLUG . '/payouts/checks?run=00000000-0000-7000-8000-000000000099');
        self::assertSame(200, $missing->status);
        self::assertStringNotContainsString('does not exist', $missing->body);
        self::assertStringContainsString('That payout is missing.', $missing->body);
        $unknown = $client->get('/' . ProbeMall::SLUG . '/payouts/checks?run=not-a-payout');
        self::assertSame(200, $unknown->status);
        self::assertStringNotContainsString('does not exist', $unknown->body);
        self::assertStringContainsString('That payout is missing.', $unknown->body);
        $this->openPrintedChecks($client, $page->body . $past->body);
        // __construct( show( past( close( checks( window( render( report( row( shown( printable( fillBlanks( blankChecks( drawer( day( finishedRun( savedChecks( numberChecks( savedPack( openPack( pay( tender( stamp( begin( matchingRun( sameWindow( assignNumbers( attach( sentence( facts( note( payments( charges( days( present( ask( numberForm( faces( printable( fillBlanks( blankChecks(
        self::assertSees('Past payouts', $past);
    }

    private function openPrintedChecks(FormClient $client, string $html): void
    {
        $matched = preg_match_all('#/' . ProbeMall::SLUG . '/payouts/checks\?run=([0-9a-fA-F-]{36})#', $html, $runs);
        if ($matched === false || $matched === 0) {
            return;
        }
        $seen = [];
        foreach ($runs[1] as $runId) {
            if (isset($seen[$runId])) {
                continue;
            }
            $seen[$runId] = true;
            $checks = $client->get('/' . ProbeMall::SLUG . '/payouts/checks?run=' . $runId);
            self::assertSame(200, $checks->status);
            self::assertStringNotContainsString('does not exist', $checks->body);
            self::assertTrue(
                str_contains($checks->body, 'Pay to the order of')
                || str_contains($checks->body, 'Type the number on the first check.')
                || str_contains($checks->body, 'First check number')
                || str_contains($checks->body, 'Those checks are not ready to print.')
                || str_contains($checks->body, 'Nobody on this payout is getting a check.')
                || str_contains($checks->body, 'That check was voided.')
                || str_contains($checks->body, 'That payout is missing.')
            );
        }
    }
}
