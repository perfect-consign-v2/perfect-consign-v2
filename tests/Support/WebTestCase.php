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

    protected static function assertSees(string $text, PageResponse|string $page): void
    {
        $body = $page instanceof PageResponse ? $page->body : $page;
        $detail = $page instanceof PageResponse ? (string) $page->status : '';
        $error = HtmlPage::error($body);
        if ($error !== '') {
            $detail .= ' ' . $error;
        }
        self::assertStringContainsString($text, $body, trim($detail));
    }
}
