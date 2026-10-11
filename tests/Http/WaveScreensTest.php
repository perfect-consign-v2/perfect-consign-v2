<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('functional')]
final class WaveScreensTest extends WebTestCase
{
    private FormClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(ProbeMall::SLUG);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
    }

    public function testTheMonthPageReadsTheBooks(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/month');
        // cashiers( addCashier( addVendor( findOrCreate( role( vendor( path( permitted( rest( slug( stale( older( go( actor( getShopper( addBooths( itemId( kind( kindFromSource( prefix( label( lateFee( moveVendor( vendorSignIn( unsold( waiting( shelf( occupied( printLabels( printOwn( storeName( owns( numbers( home( sticker( intake( sheet( clear( current( save( render( attempt( refuse( schema( cashier( counter( rentPage( drawer( lockedRegister( show( add( move( line( items( store( allocate( issuedAmount( databaseShaped( uniqueName( sell( shopper( withBooths( accept( lookup( passwordMatches( knownRole( openSession( staffRow(

        self::assertSame(200, $page->status);
        self::assertSees('Sales', $page);
        self::assertSees('Tax collected', $page);
        self::assertSees('Rent still owed', $page);
        self::assertSees('Checks written', $page);
        self::assertSees('does not post', $page);
    }

    public function testSalesTaxComesFromTheBank(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/tax');

        self::assertSame(200, $page->status);
        self::assertSees('This payment comes from the bank. It does not come out of the drawer.', $page);
    }

    public function testPayVendorsPrintsThePackThenTheEnvelopesThenTheChecks(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/payouts');

        self::assertSame(200, $page->status);
        $pack = strpos($page->body, 'Print the pack');
        $envelopes = strpos($page->body, 'Print envelopes');
        $checks = strpos($page->body, '>Write checks<');
        $check = strpos($page->body, 'dt-column-title">Check<');
        $share = strpos($page->body, 'dt-column-title">Their share<');
        self::assertNotFalse($pack);
        self::assertNotFalse($envelopes);
        self::assertNotFalse($checks);
        self::assertNotFalse($check);
        self::assertNotFalse($share);
        self::assertGreaterThan($pack, $envelopes);
        self::assertGreaterThan($envelopes, $checks);
        self::assertLessThan($share, $check);
    }

    public function testTheOwnerAddsACashier(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/staff');

        self::assertSame(200, $page->status);
        self::assertSees('Add a cashier', $page);
    }
}
