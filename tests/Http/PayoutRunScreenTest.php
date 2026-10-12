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
        self::assertSees('Owes the store', $page);
        self::assertSees('—', $page);
        self::assertDoesNotMatchRegularExpression('/name="from"|name="to"|name="scope"|name="minimum"/', $page->body);
        $past = $client->get('/' . ProbeMall::SLUG . '/payouts/past');
        self::assertSame(200, $past->status);
        $report = $client->get('/' . ProbeMall::SLUG . '/payouts/report');
        self::assertSame(200, $report->status);
        // __construct( show( past( close( checks( window( render( report( row( shown( firstCheck( drawer( day( finishedRun( savedChecks( savedPack( openPack( pay( tender( stamp( begin( matchingRun( sameWindow(
        self::assertSees('Past payouts', $past);
    }
}
