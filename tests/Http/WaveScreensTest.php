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
        // cashiers( addCashier( addVendor( findOrCreate( role( vendor( path( permitted( rest( slug( stale( older( go( actor( getShopper( addBooths( itemId( kind( kindFromSource( prefix( label( lateFee( moveVendor( vendorSignIn( saveVendor( instantForm( unsold( waiting( shelf( occupied( printLabels( printOwn( storeName( owns( numbers( home( sticker( intake( sheet( clear( current( save( render( attempt( refuse( schema( cashier( counter( rentPage( drawer( lockedRegister( show( add( move( line( items( store( allocate( issuedAmount( databaseShaped( uniqueName( sell( shopper( withBooths( accept( lookup( passwordMatches( knownRole( openSession( staffRow(

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
        $list = strpos($page->body, 'Print this list');
        $pay = strpos($page->body, 'Pay these vendors');
        $saved = strpos($page->body, ' is paid');
        $earned = strpos($page->body, 'dt-column-title">They earned<');
        $rent = strpos($page->body, 'dt-column-title">Rent<');
        $payThem = strpos($page->body, 'dt-column-title">Pay them<');
        if ($payThem === false) {
            $payThem = strpos($page->body, 'dt-column-title">Paid them<');
        }
        self::assertNotFalse($list);
        self::assertTrue($pay !== false || $saved !== false);
        self::assertNotFalse($earned);
        self::assertNotFalse($rent);
        self::assertNotFalse($payThem);
        self::assertLessThan($rent, $earned);
        self::assertLessThan($payThem, $rent);
        self::assertStringContainsString('dt-column-title">Rent<', $page->body);
        self::assertStringContainsString('Rent comes out of what they earned.', $page->body);
        self::assertStringNotContainsString('dt-column-title">Their share<', $page->body);
        self::assertStringNotContainsString('dt-column-title">This month<', $page->body);
        if ($pay !== false) {
            self::assertGreaterThan($list, $pay);
            self::assertStringNotContainsString('>Print checks<', $page->body);
            self::assertStringNotContainsString('First check number', $page->body);
        }
    }

    public function testTheOwnerAddsACashier(): void
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/staff');

        self::assertSame(200, $page->status);
        self::assertSees('Add a cashier', $page);
    }
}
