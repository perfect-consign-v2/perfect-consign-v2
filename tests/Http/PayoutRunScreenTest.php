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
        self::assertSees('Pay vendors', $page);
        self::assertSees('Print the pack', $page);
        self::assertSees('window.print()', $page);
        self::assertSees('class="envelope"', $page);
        self::assertDoesNotMatchRegularExpression('/name="from"|name="to"|name="scope"|name="minimum"/', $page->body);
        $past = $client->get('/' . ProbeMall::SLUG . '/payouts/past');
        self::assertSame(200, $past->status);
        // __construct( show( past( close( checks( window( render( row(
        self::assertSees('Past payouts', $past);
    }
}
