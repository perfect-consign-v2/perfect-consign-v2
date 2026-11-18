<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use DateTimeImmutable;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class StandingListHttpTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testInactiveNamesLeaveTheDefaultList(): void
    {
        $saved = $this->client->post('/' . self::SLUG . '/settings/building', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'use_color' => '1',
            'use_category' => '1',
            'use_size' => '1',
            'use_condition' => '1',
            'use_department' => '1',
            'use_brand' => '1',
            'vendor_portal_enabled' => '1',
        ]);
        self::assertSees('Building saved.', $saved);
        $name = 'c' . bin2hex(random_bytes(4));
        $created = $this->client->post('/' . self::SLUG . '/colors', ['name' => $name]);
        self::assertSees('Color created.', $created);
        self::assertSees($name, $created);
        $id = HtmlPage::taggedId($created->body, 'tr', 'color', $name);

        $hidden = $this->client->post('/' . self::SLUG . '/colors/' . $id . '/active', ['active' => '0']);
        self::assertSees('Color is inactive.', $hidden);
        self::assertSees('Make active', $hidden);
        self::assertStringContainsString('status=inactive', $hidden->body);

        $active = $this->client->get('/' . self::SLUG . '/colors');
        self::assertStringNotContainsString($name, $active->body);
        $inactive = $this->client->get('/' . self::SLUG . '/colors?status=inactive');
        self::assertSees($name, $inactive);
        self::assertSees('Make active', $inactive);

        $shown = $this->client->post('/' . self::SLUG . '/colors/' . $id . '/active', ['active' => '1']);
        self::assertSees('Color is active.', $shown);
        self::assertSees($name, $shown);
        $stillHidden = $this->client->get('/' . self::SLUG . '/colors?status=inactive');
        self::assertStringNotContainsString($name, $stillHidden->body);
    }

    public function testABoothHidesUntilItIsMadeActiveAgain(): void
    {
        $vendor = $this->vendor();
        $free = $this->booth('free');
        $open = $this->booth('open');
        $later = $this->booth('later');
        $ended = $this->booth('ended');
        $people = $this->client->get('/' . self::SLUG . '/vendors');
        $vendorId = HtmlPage::taggedId($people->body, 'tr', 'vendor', $vendor);
        $seller = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSame(1, preg_match('/data-today="(\d{4}-\d{2}-\d{2})"/', $seller->body, $today));
        $day = new DateTimeImmutable($today[1]);
        $this->assign($open['id'], $vendorId, ProbeMall::PERIOD_START, '');
        $this->assign($later['id'], $vendorId, $day->modify('+30 days')->format('Y-m-d'), '');
        $this->assign($ended['id'], $vendorId, $day->modify('-1 day')->format('Y-m-d'), $day->modify('-1 day')->format('Y-m-d'));

        $offered = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertSees($free['code'], $offered);
        $held = $this->client->get('/' . self::SLUG . '/booths/' . $open['id'] . '/edit');
        self::assertStringNotContainsString('Make inactive', $held->body);
        $empty = $this->client->get('/' . self::SLUG . '/booths/' . $free['id'] . '/edit');
        self::assertSees('Make inactive', $empty);
        self::assertStringContainsString('>Status<', $empty->body);
        self::assertStringNotContainsString('>Standing<', $empty->body);
        $hidden = $this->client->post('/' . self::SLUG . '/booths/' . $free['id'] . '/active', ['active' => '0']);
        self::assertSees('Booth is inactive.', $hidden);
        self::assertSees('Make active', $hidden);

        $list = $this->client->get('/' . self::SLUG . '/booths');
        self::assertStringNotContainsString($free['code'], $list->body);
        self::assertSees($open['code'], $list);
        self::assertSame(1, preg_match('/<tr id="booth-' . $open['id'] . '">.*?<\/tr>/s', $list->body, $openRow));
        self::assertStringNotContainsString('Make inactive', $openRow[0]);
        self::assertSame(1, preg_match('/<tr id="booth-' . $ended['id'] . '">.*?<\/tr>/s', $list->body, $endedRow));
        self::assertStringContainsString('Make inactive', $endedRow[0]);
        $stayed = $this->client->post('/' . self::SLUG . '/booths/' . $open['id'] . '/active', [
            'active' => '0',
            'back' => 'booth',
        ]);
        self::assertSees('Edit booth', $stayed);
        self::assertStringNotContainsString('>Make inactive<', $stayed->body);
        self::assertSees('Cannot set booth to inactive while it has a current or scheduled assignment. End assignments first.', $stayed);
        $inactive = $this->client->get('/' . self::SLUG . '/booths?visibility=inactive');
        self::assertSees($free['code'], $inactive);
        self::assertSees('Make active', $inactive);
        $choices = $this->client->get('/' . self::SLUG . '/vendors/' . $vendorId);
        self::assertStringNotContainsString($free['code'], $choices->body);
        $map = $this->client->get('/' . self::SLUG . '/map/edit');
        self::assertStringNotContainsString($free['code'], $map->body);
        self::assertSees($open['code'], $map);

        $refused = $this->client->post('/' . self::SLUG . '/booths/' . $open['id'] . '/active', ['active' => '0']);
        self::assertSees('Cannot set booth to inactive while it has a current or scheduled assignment. End assignments first.', $refused);
        self::assertSees($open['code'], $this->client->get('/' . self::SLUG . '/booths'));
        $scheduled = $this->client->post('/' . self::SLUG . '/booths/' . $later['id'] . '/active', ['active' => '0']);
        self::assertSees('Cannot set booth to inactive while it has a current or scheduled assignment. End assignments first.', $scheduled);

        $retired = $this->client->post('/' . self::SLUG . '/booths/' . $ended['id'] . '/active', ['active' => '0']);
        self::assertSees('Booth is inactive.', $retired);
        self::assertStringNotContainsString($ended['code'], $this->client->get('/' . self::SLUG . '/booths')->body);

        $shown = $this->client->post('/' . self::SLUG . '/booths/' . $free['id'] . '/active', ['active' => '1']);
        self::assertSees('Booth is active.', $shown);
        self::assertSees($free['code'], $shown);
    }

    private function vendor(): string
    {
        $code = 'v' . bin2hex(random_bytes(4));
        $page = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        self::assertSees($code, $page);

        return $code;
    }

    /**
     * @return array{id: string, code: string}
     */
    private function booth(string $label): array
    {
        $code = $label . bin2hex(random_bytes(3));
        $page = $this->client->post('/' . self::SLUG . '/booths', [
            'code' => $code,
            'name' => $code,
        ]);
        self::assertSees('Booth created.', $page);

        return ['id' => HtmlPage::taggedId($page->body, 'tr', 'booth', $code), 'code' => $code];
    }

    private function assign(string $boothId, string $vendorId, string $startsOn, string $endsOn): void
    {
        $page = $this->client->post('/' . self::SLUG . '/assignments', [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'rent' => '10.00',
        ]);
        self::assertSees('Booth assigned.', $page);
    }
}
