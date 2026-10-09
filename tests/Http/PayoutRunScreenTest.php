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
        self::assertSees('Print the pack', $page);
        self::assertSees('window.print()', $page);
        self::assertSees('Close ', $page);
        self::assertSees('Write checks', $page);
        self::assertSees('class="vendor-sheet"', $page);
        self::assertSees('Print envelopes', $page);
        self::assertSees('Owes the store', $page);
        self::assertSees('—', $page);
        self::assertDoesNotMatchRegularExpression('/name="from"|name="to"|name="scope"|name="minimum"/', $page->body);
        $past = $client->get('/' . ProbeMall::SLUG . '/payouts/past');
        self::assertSame(200, $past->status);
        $report = $client->get('/' . ProbeMall::SLUG . '/payouts/report');
        self::assertSame(200, $report->status);
        // __construct( show( past( close( checks( window( render( report( row( shown(
        self::assertSees('Past payouts', $past);
    }
}
