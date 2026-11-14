<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\PageResponse;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class BoothFloorMallCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const MISSING = '01900000-0000-7000-8000-00000000c0ff';

    private FormClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(self::SLUG);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testBoothExtrasChargeDepositAndRelease(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/booth-extras');
        self::assertSees('Booth extras', $page);
        self::assertSees('Collect deposit', $page);
        self::assertSees('Return deposit', $page);
        self::assertStringNotContainsString('<p>a booth and a reference are required</p>', $page->body);

        $missing = $this->charge(['booth_id' => '', 'source_reference' => '', 'amount' => '5.00']);
        self::assertSees('a booth and a reference are required', $missing);
        $badBooth = $this->charge([
            'booth_id' => 'not-a-booth',
            'source_reference' => 'ref-' . bin2hex(random_bytes(3)),
            'amount' => '5.00',
        ]);
        self::assertSees('a booth and a reference are required', $badBooth);
        $unknown = $this->charge([
            'booth_id' => self::MISSING,
            'source_reference' => 'ref-' . bin2hex(random_bytes(3)),
            'amount' => '5.00',
        ]);
        self::assertSees('that booth does not exist', $unknown);

        $tag = bin2hex(random_bytes(3));
        $booth = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => 'x' . $tag,
            'name' => 'Extra ' . $tag,
        ]);
        self::assertSees('Booth created.', $booth);
        $boothId = HtmlPage::taggedId($booth->body, 'tr', 'booth', 'x' . $tag);
        $badAmount = $this->charge([
            'booth_id' => $boothId,
            'source_reference' => 'ref-' . bin2hex(random_bytes(3)),
            'amount' => 'five',
        ]);
        self::assertSees('enter an amount in dollars and cents', $badAmount);
        $zero = $this->charge([
            'booth_id' => $boothId,
            'source_reference' => 'ref-' . bin2hex(random_bytes(3)),
            'amount' => '0.00',
        ]);
        self::assertSees('the amount must be greater than zero', $zero);
        $reference = 'chg-' . $tag;
        $charged = $this->charge([
            'booth_id' => $boothId,
            'source_reference' => $reference,
            'amount' => '5.00',
        ]);
        self::assertSees('Booth charge posted.', $charged);
        $again = $this->charge([
            'booth_id' => $boothId,
            'source_reference' => $reference,
            'amount' => '5.00',
        ]);
        self::assertSees('That could not be saved.', $again);

        $this->ensureTwoOpenDrawers();
        $other = self::client();
        $signed = $other->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signed);
        $refused = $other->post('/' . self::SLUG . '/booth-deposit', [
            'booth_id' => $boothId,
            'source_reference' => 'dep-closed-' . $tag,
            'amount' => '20.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('open a register before taking a deposit', $refused);
        $this->useARegister();
        $depositRef = 'dep-' . $tag;

        $deposited = $this->client->post('/' . self::SLUG . '/booth-deposit', [
            'booth_id' => $boothId,
            'source_reference' => $depositRef,
            'amount' => '20.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('a drawer journal needs an open register', $deposited);
        $released = $this->client->post('/' . self::SLUG . '/booth-deposit/release', [
            'booth_id' => $boothId,
            'source_reference' => 'rel-' . $tag,
            'amount' => '20.00',
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('a release is larger than the deposit held', $released);
    }

    public function testTheFloorNotesAFindingAndAPickup(): void
    {
        $empty = $this->client->get('/' . self::SLUG . '/floor');
        self::assertSees('Floor', $empty);
        self::assertSees('Save finding', $empty);
        self::assertSees('Save pickup', $empty);

        $badKind = $this->client->post('/' . self::SLUG . '/floor', [
            'code' => 'missing-tag',
            'kind' => 'lost',
            'noted_on' => ProbeMall::POSTING_DATE,
            'noted_by' => 'Ada',
        ]);
        self::assertSees('Choose missing or misplaced.', $badKind);

        $tag = bin2hex(random_bytes(3));
        $vendor = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => 'f' . $tag,
            'first_name' => 'Flo',
            'last_name' => $tag,
        ]);
        self::assertSees('Vendor created.', $vendor);
        $vendorId = HtmlPage::pathId($vendor->body, self::SLUG . '/vendors');
        $code = 't' . $tag;
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => 'Floor ' . $tag,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '6.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);

        $unknown = $this->client->post('/' . self::SLUG . '/floor', [
            'code' => 'no-such-' . $tag,
            'kind' => 'missing',
            'noted_on' => ProbeMall::POSTING_DATE,
            'noted_by' => 'Ada',
        ]);
        self::assertSees('expected a string', $unknown);
        $spaced = $this->client->post('/' . self::SLUG . '/floor', [
            'code' => $code,
            'kind' => 'misplaced',
            'noted_on' => ProbeMall::POSTING_DATE,
            'noted_by' => str_repeat('A', 81),
        ]);
        self::assertSees('That could not be saved.', $spaced);

        $noted = $this->client->post('/' . self::SLUG . '/floor', [
            'code' => $code,
            'kind' => 'missing',
            'noted_on' => ProbeMall::POSTING_DATE,
            'noted_by' => 'Ada ' . $tag,
        ]);
        self::assertSees('Floor note saved.', $noted);
        self::assertSees($code, $noted);
        self::assertSees('Ada ' . $tag, $noted);

        $noPickup = $this->client->post('/' . self::SLUG . '/pickups', [
            'code' => 'no-such-' . $tag,
            'picked_up_on' => ProbeMall::POSTING_DATE,
            'received_by' => 'Bea',
        ]);
        self::assertSees('expected a string', $noPickup);
        $picked = $this->client->post('/' . self::SLUG . '/pickups', [
            'code' => $code,
            'picked_up_on' => ProbeMall::POSTING_DATE,
            'received_by' => 'Bea ' . $tag,
        ]);
        self::assertSees('Pickup saved.', $picked);
        self::assertSees('Bea ' . $tag, $picked);
    }

    public function testAMallSaleIsNamedAndRefusedWhenTheSpanOrPercentIsWrong(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/mall-sale');
        self::assertSees('Mall sale', $page);
        self::assertSees('Absorbed by', $page);

        $tag = bin2hex(random_bytes(3));
        $bad = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Sale ' . $tag,
            'starts_on' => '2091-04-01',
            'ends_on' => '2091-04-02',
            'percent' => 'ten',
            'absorbed_by' => 'mall',
        ]);
        self::assertSees('Name who absorbs the sale and the percent.', $bad);
        $who = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Sale ' . $tag,
            'starts_on' => '2091-04-01',
            'ends_on' => '2091-04-02',
            'percent' => '10',
            'absorbed_by' => 'both',
        ]);
        self::assertSees('Name who absorbs the sale and the percent.', $who);
        $low = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Sale ' . $tag,
            'starts_on' => '2091-04-01',
            'ends_on' => '2091-04-02',
            'percent' => '0',
            'absorbed_by' => 'mall',
        ]);
        self::assertSees('The percent has to be between 0 and 50.', $low);
        $high = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Sale ' . $tag,
            'starts_on' => '2091-04-01',
            'ends_on' => '2091-04-02',
            'percent' => '50.01',
            'absorbed_by' => 'vendor',
        ]);
        self::assertSees('The percent has to be between 0 and 50.', $high);

        $year = random_int(2400, 2499);
        $month = str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string) random_int(1, 28), 2, '0', STR_PAD_LEFT);
        $start = $year . '-' . $month . '-' . $day;
        $saved = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Mall ' . $tag,
            'starts_on' => $start,
            'ends_on' => $start,
            'percent' => '10.5',
            'absorbed_by' => 'mall',
        ]);
        self::assertSees('Mall sale saved.', $saved);
        self::assertSees('Mall ' . $tag, $saved);
        $overlap = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Overlap ' . $tag,
            'starts_on' => $start,
            'ends_on' => $start,
            'percent' => '5',
            'absorbed_by' => 'vendor',
        ]);
        self::assertStringContainsString('exclusion constraint', $this->flash($overlap->body), $this->flash($overlap->body));
        $next = ($year + 1) . '-' . $month . '-' . $day;
        $vendor = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Vendor ' . $tag,
            'starts_on' => $next,
            'ends_on' => $next,
            'percent' => '0.5',
            'absorbed_by' => 'vendor',
        ]);
        self::assertSees('Mall sale saved.', $vendor);
        $backwards = $this->client->post('/' . self::SLUG . '/mall-sale', [
            'name' => 'Back ' . $tag,
            'starts_on' => '2093-06-10',
            'ends_on' => '2093-06-01',
            'percent' => '1',
            'absorbed_by' => 'mall',
        ]);
        self::assertSees('That could not be saved.', $backwards);
    }

    /**
     * @param array{booth_id: string, source_reference: string, amount: string} $fields
     */
    private function charge(array $fields): PageResponse
    {
        return $this->client->post('/' . self::SLUG . '/booth-extras', [
            ...$fields,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
    }

    private function ensureTwoOpenDrawers(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        $open = preg_match_all('/data-open-modal="close-/', $page->body);
        $open = $open === false ? 0 : $open;
        while ($open < 2) {
            $name = 'Desk ' . bin2hex(random_bytes(3));
            $created = $this->client->post('/' . self::SLUG . '/registers', ['name' => $name]);
            self::assertSees($name, $created);
            $registerId = $this->match(
                $created->body,
                '/id="register-([0-9a-fA-F-]{36})">\s*<div class="card-head">\s*<div class="h3">' . preg_quote($name, '/') . '/'
            );
            $opened = $this->client->post('/' . self::SLUG . '/registers/' . $registerId . '/open', [
                'cashier' => ProbeMall::USERNAME,
                'opening_float' => '0.00',
                ...$this->zeroCounts(),
            ]);
            self::assertSees('Register opened.', $opened);
            $open++;
        }
    }

    private function useARegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $active = $this->client->post($use[1], []);
            self::assertSees('is the active register.', $active);

            return;
        }
        self::assertStringContainsString('Close register', $page->body);
    }

    /**
     * @return array<string, string>
     */
    private function zeroCounts(): array
    {
        $fields = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }

        return $fields;
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }

    private function flash(string $html): string
    {
        if (preg_match('/data-message="([^"]*)"/', $html, $match) !== 1) {
            return $html;
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }
}
