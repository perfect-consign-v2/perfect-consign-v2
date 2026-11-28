<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class WebTestCase extends TestCase
{
    private static int $users = 0;

    private static ?ProbeServer $server = null;

    protected static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$users === 0) {
            $root = dirname(__DIR__, 2);
            ProbeMall::ensure($root);
            self::$server = ProbeServer::start($root);
            self::$baseUrl = self::$server->baseUrl();
        }
        self::$users++;
    }

    public static function tearDownAfterClass(): void
    {
        self::$users--;
        if (self::$users === 0 && self::$server !== null) {
            self::$server->stop();
            self::$server = null;
            self::$baseUrl = '';
        }
        parent::tearDownAfterClass();
    }

    protected static function client(): FormClient
    {
        return new FormClient(self::$baseUrl);
    }

    protected static function clearMailbox(): void
    {
        $written = file_put_contents(ProbeServer::mailFile(), '');
        if ($written === false) {
            self::fail('the mail file could not be cleared');
        }
    }

    protected static function mailbox(): string
    {
        $text = file_get_contents(ProbeServer::mailFile());

        return is_string($text) ? $text : '';
    }

    protected static function flushMail(): void
    {
        MailWorker::flush();
    }

    protected static function makeRoomForAFloor(FormClient $client, string $slug): void
    {
        $editor = $client->get('/' . $slug . '/map/edit');
        $found = preg_match_all(
            '/data-floor="([0-9a-fA-F-]{36})" data-floor-name="([^"]+)"/',
            $editor->body,
            $floors,
            PREG_SET_ORDER
        );
        if ($found === false || count($floors) < 20) {
            return;
        }
        foreach ($floors as $floor) {
            if (str_starts_with($floor[2], 'Loft ') || str_starts_with($floor[2], 'Wing ')) {
                $client->post('/' . $slug . '/map/floors/' . $floor[1] . '/delete', []);

                return;
            }
        }
    }

    /**
     * The Charge button a person would press on the rent page for that booth:
     * the stay and rent period it posts. Fails when the booth has no button.
     *
     * @return array{assignment_id: string, period_start: string}
     */
    protected static function chargeButton(string $page, string $boothCode): array
    {
        $pattern = '/data-charge-stay="([0-9a-f-]{36})"\s+data-charge-period="(\d{4}-\d{2}-\d{2})"[^>]*data-charge-code="'
            . preg_quote($boothCode, '/') . '"/';
        if (preg_match($pattern, $page, $found) !== 1) {
            self::fail('the rent page has no charge button for booth ' . $boothCode);
        }

        return ['assignment_id' => $found[1], 'period_start' => $found[2]];
    }

    /**
     * The value of a "Why are there fewer?" button, by its label. Fails when the
     * page does not ask or has no button with that label.
     */
    protected static function countReason(string $page, string $label): string
    {
        $pattern = '/name="quantity_reason" value="([0-9a-f-]{36})">' . preg_quote($label, '/') . '</';
        if (str_contains($page, 'Why are there fewer?') === false || preg_match($pattern, $page, $found) !== 1) {
            self::fail('the page does not ask why there are fewer with a ' . $label . ' button');
        }

        return $found[1];
    }

    /**
     * The first day of this rent period on the mall clock, from the rent page.
     */
    protected static function thisMonthStart(FormClient $client, string $slug): string
    {
        $rent = $client->get('/' . $slug . '/rent');
        if (preg_match('/<time datetime="(\d{4}-\d{2})-\d{2}">/', $rent->body, $month) !== 1) {
            self::fail('the rent page shows no date');
        }

        return $month[1] . '-01';
    }

    /**
     * Uses a register shift that is open, or opens one, so money can be taken.
     */
    protected static function useOpenRegister(FormClient $client, string $slug): void
    {
        $page = $client->get('/' . $slug . '/registers');
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $client->post($use[1], []);

            return;
        }
        if (preg_match('#/' . $slug . '/registers/[0-9a-fA-F-]{36}/close#', $page->body) === 1) {
            return;
        }
        if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/open)#', $page->body, $open) !== 1) {
            $created = $client->post('/' . $slug . '/registers', ['name' => 'Desk ' . bin2hex(random_bytes(2))]);
            if (preg_match('#(/' . $slug . '/registers/[0-9a-fA-F-]{36}/open)#', $created->body, $open) !== 1) {
                self::fail('no register could be opened');
            }
        }
        $fields = ['cashier' => ProbeMall::USERNAME, 'opening_float' => '0.00'];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $bill) {
            $fields['count_' . $bill] = '0';
        }
        self::assertSees('Register opened.', $client->post($open[1], $fields));
    }

    /**
     * Assigns a booth at the desk the way a person does: choose it, see what
     * is due, then take that first rent. Returns the page after assigning.
     *
     * @param array{booth_id: string, vendor_id: string, starts_on: string, ends_on?: string, rent?: string} $stay
     */
    protected static function assignAtDesk(FormClient $client, string $slug, array $stay, string $payWith = 'card', string $check = ''): PageResponse
    {
        $stay += ['ends_on' => '', 'rent' => ''];
        $quoted = $client->post('/' . $slug . '/assignments', $stay);
        self::assertSees('Due now:', $quoted);
        $assigned = $client->post('/' . $slug . '/assignments', $stay + ['pay_with' => $payWith, 'check_number' => $check]);
        self::assertSees('Booth assigned.', $assigned);

        return $assigned;
    }

    protected static function assertSees(string $text, PageResponse|string $page): void
    {
        $body = $page instanceof PageResponse ? $page->body : $page;
        $detail = $page instanceof PageResponse ? (string) $page->status : '';
        $error = HtmlPage::error($body);
        if ($error !== '') {
            $detail .= ' ' . $error;
        }
        $plain = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertTrue(
            str_contains($body, $text) || str_contains($plain, $text),
            trim($detail) . "\nFailed asserting that the page contains " . $text
        );
    }
}
