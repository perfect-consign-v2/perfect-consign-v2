<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

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
            str_contains($page->body, 'Pay these vendors') || str_contains($page->body, ' is paid.')
        );
        self::assertStringNotContainsString('vendor-sheet', $page->body);
        self::assertStringNotContainsString('Print the pack', $page->body);
        self::assertStringNotContainsString('Print envelopes', $page->body);
        self::assertSees('Pay them', $page);
        self::assertSees('They earned', $page);
        self::assertSees('Rent', $page);
        self::assertSees('Rent comes out of what they earned.', $page);
        self::assertSees('Owes the store', $page);
        self::assertStringNotContainsString('First check number', $page->body);
        self::assertStringNotContainsString('Their share', $page->body);
        self::assertStringNotContainsString('Owed before', $page->body);
        self::assertStringNotContainsString('This month', $page->body);
        self::assertStringNotContainsString('Paid since', $page->body);
        self::assertSees('—', $page);
        self::assertDoesNotMatchRegularExpression('/name="from"|name="to"|name="scope"|name="minimum"/', $page->body);
        $past = $client->get('/' . ProbeMall::SLUG . '/payouts/past');
        self::assertSame(200, $past->status);
        $report = $client->get('/' . ProbeMall::SLUG . '/payouts/report');
        self::assertSame(200, $report->status);
        // __construct( show( past( close( checks( window( render( report( row( shown( printable( fillBlanks( blankChecks( drawer( day( finishedRun( savedChecks( numberChecks( savedPack( openPack( pay( tender( stamp( begin( matchingRun( sameWindow( assignNumbers( attach( sentence( facts( note( payments( charges( days( present( ask( numberForm( faces( printable( fillBlanks( blankChecks(
        self::assertSees('Past payouts', $past);
    }
}
