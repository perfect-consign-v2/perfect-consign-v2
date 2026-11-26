<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ItemListBody;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PartyRowsHttpTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testThePageIsAShellAndEveryPartyIsOnSomePage(): void
    {
        $slug = ProbeMall::SLUG;
        $tag = 'w' . bin2hex(random_bytes(3));
        $waiting = $this->client->post('/' . $slug . '/wait-list', [
            'first_name' => 'Zola',
            'last_name' => $tag,
            'email' => 'zola-' . $tag . '@example.test',
            'phone' => '555-0141',
        ]);
        self::assertSees('Added to the wait list.', $waiting);

        $shell = $this->client->get('/' . $slug . '/parties');
        self::assertSame(200, $shell->status);
        self::assertStringContainsString('data-dt-server="1"', $shell->body);
        self::assertStringContainsString('data-dt-rows="/' . $slug . '/parties/rows"', $shell->body);
        self::assertStringContainsString('<tbody></tbody>', $shell->body);
        self::assertStringNotContainsString('Zola ' . $tag, $shell->body);

        $everyone = $this->payload('/' . $slug . '/parties/rows?length=10');
        $total = $everyone['recordsTotal'];
        self::assertIsInt($total);
        self::assertGreaterThan(0, $total);
        self::assertSame($total, $everyone['recordsFiltered']);

        $lastPage = $this->payload('/' . $slug . '/parties/rows?length=10&start=' . ($total - 1));
        self::assertIsArray($lastPage['data']);
        self::assertCount(1, $lastPage['data']);

        $found = ItemListBody::html(
            $this->client,
            '/' . $slug . '/parties/rows?' . http_build_query(['search' => ['value' => 'zola ' . $tag]])
        );
        self::assertMatchesRegularExpression(
            '#<a href="/' . $slug . '/parties/[0-9a-f-]{36}">Zola ' . $tag . '</a></td><td>person</td><td>wait list</td>#',
            $found
        );
    }

    public function testABadAskIsRefusedInPlainWords(): void
    {
        $length = $this->client->get('/' . ProbeMall::SLUG . '/parties/rows?length=7');
        self::assertSame(422, $length->status);
        self::assertStringContainsString('That page size is not allowed.', $length->body);

        $sort = $this->client->get('/' . ProbeMall::SLUG . '/parties/rows?' . http_build_query([
            'order' => [['column' => '0', 'dir' => 'asc']],
            'columns' => [['name' => 'party.id; drop table parties']],
        ]));
        self::assertSame(422, $sort->status);
        self::assertStringContainsString('That column cannot be sorted.', $sort->body);
    }

    /**
     * @return array<mixed>
     */
    private function payload(string $path): array
    {
        $response = $this->client->get($path);
        self::assertSame(200, $response->status);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);

        return $payload;
    }
}
