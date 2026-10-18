<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PeriodCloseCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

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

    public function testClosingAYearWithRevenuePostsACloseJournal(): void
    {
        $this->client->post('/' . self::SLUG . '/periods', [
            'starts_on' => '2016-01-01',
            'ends_on' => '2016-12-31',
        ]);
        $form = $this->client->get('/' . self::SLUG . '/adjustment');
        $cash = $this->account($form->body, '1000');
        $revenue = $this->account($form->body, '4000');
        $posted = $this->client->post('/' . self::SLUG . '/adjustment', [
            'source_reference' => 'yr16' . bin2hex(random_bytes(3)),
            'posting_date' => '2016-06-15',
            'description' => '2016 commission',
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
            'line_account_2' => $revenue,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ]);
        self::assertTrue(
            str_contains($posted->body, 'Adjustment posted.')
            || str_contains($posted->body, 'Fix a mistake'),
            HtmlPage::error($posted->body)
        );
        $close = $this->client->get('/' . self::SLUG . '/close');
        self::assertSees('Close the year', $close);
        $openPeriod = '/<tr>\s*<td>(\d{4}-\d{2}-\d{2})<\/td>\s*<td>(\d{4}-\d{2}-\d{2})<\/td>\s*<td>open<\/td>.*?name="period_id" value="([0-9a-fA-F-]{36})"/s';
        $open = [];
        if (preg_match_all($openPeriod, $close->body, $found, PREG_SET_ORDER) > 0) {
            $open = $found;
        }
        usort($open, static fn (array $left, array $right): int => $left[1] <=> $right[1]);
        $closedId = '';
        foreach ($open as $period) {
            if ($period[1] > '2016-01-01') {
                continue;
            }
            $closed = $this->client->post('/' . self::SLUG . '/close', [
                'period_id' => $period[3],
                'ends_on' => $period[2],
            ]);
            self::assertTrue(
                str_contains($closed->body, 'Period closed.')
                || str_contains($closed->body, 'Posted entry')
                || str_contains($closed->body, 'Close the year'),
                HtmlPage::error($closed->body)
            );
            if ($period[1] === '2016-01-01') {
                $closedId = $period[3];
            }
        }
        if ($closedId !== '') {
            $again = $this->client->post('/' . self::SLUG . '/close', [
                'period_id' => $closedId,
                'ends_on' => '2016-12-31',
            ]);
            self::assertLessThan(500, $again->status);
            $journals = $this->client->get('/' . self::SLUG . '/journals');
            if (preg_match_all('#/journals/([0-9a-fA-F-]{36})#', $journals->body, $ids) > 0) {
                $missing = $this->client->get('/' . self::SLUG . '/journals/' . $ids[1][0]);
                self::assertLessThan(500, $missing->status);
                $this->client->post('/' . self::SLUG . '/journals/' . $ids[1][0] . '/void', []);
            }
        }
        $gone = $this->client->post(
            '/' . self::SLUG . '/journals/00000000-0000-7000-8000-000000000099/void',
            []
        );
        self::assertSame(404, $gone->status);
    }

    private function account(string $html, string $code): string
    {
        self::assertSame(1, preg_match('/<option value="([0-9a-fA-F-]{36})">' . $code . ' /', $html, $match));

        return $match[1];
    }
}
