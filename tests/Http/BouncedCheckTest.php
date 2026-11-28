<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * A bounced check is found by its number among the checks the store took, and
 * posts that check's own amount, once.
 */
final class BouncedCheckTest extends WebTestCase
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

    public function testACheckTheStoreTookIsMarkedBouncedOnceForItsOwnAmount(): void
    {
        $form = $this->client->get('/' . self::SLUG . '/checks/nsf');
        self::assertSees('Bounced check', $form);
        self::assertSees('find it by its number and mark it bounced', $form);
        self::assertStringNotContainsString('It bounced', $form->body);

        $number = 'NSF' . strtoupper(bin2hex(random_bytes(4)));
        $missing = $this->client->get('/' . self::SLUG . '/checks/nsf?number=' . $number);
        self::assertSees('No check numbered ' . $number . ' was taken.', $missing);

        $this->openRegister();
        $sold = $this->client->post('/' . self::SLUG . '/pos/gift', [
            'gift_amount' => '12.34',
            'gift_tender' => 'checks',
            'check_number' => $number,
            'form_token' => bin2hex(random_bytes(16)),
        ]);
        self::assertSame(200, $sold->status);

        $found = $this->client->get('/' . self::SLUG . '/checks/nsf?number=' . strtolower($number));
        self::assertSees('Check ' . $number . '</strong> for $12.34', $found);
        $journal = $this->match($found->body, '#name="journal_id" value="([0-9a-fA-F-]{36})"#');

        $notOne = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'journal_id' => 'nope',
            'number' => $number,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('Choose the check that bounced.', $notOne);
        $unknown = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'journal_id' => '00000000-0000-7000-8000-000000000099',
            'number' => $number,
            'posting_date' => ProbeMall::POSTING_DATE,
        ]);
        self::assertSees('That check was not found.', $unknown);

        $posted = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'journal_id' => $journal,
            'number' => $number,
            'posting_date' => '',
        ]);
        self::assertSees('Bounced check posted.', $posted);
        self::assertSees('check-return:' . $journal . ':0', $posted);
        self::assertSees('Bounced check ' . $number, $posted);
        self::assertSees('12.34', $posted);

        $again = $this->client->post('/' . self::SLUG . '/checks/nsf', [
            'journal_id' => $journal,
            'number' => $number,
            'posting_date' => '',
        ]);
        self::assertSees('That check is already marked as bounced.', $again);
        $listed = $this->client->get('/' . self::SLUG . '/checks/nsf?number=' . $number);
        self::assertSees('This check is already marked as bounced.', $listed);
        self::assertStringNotContainsString('It bounced', $listed->body);
    }

    private function openRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $fields = ['cashier' => ProbeMall::USERNAME, 'opening_float' => '0.00'];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }
        $this->client->post($this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#'), $fields);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
