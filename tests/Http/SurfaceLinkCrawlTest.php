<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Support\PageSurfaceCrawler;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class SurfaceLinkCrawlTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private static ?ProbeSurfaceSeed $seed = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $owner = self::client();
        self::$seed = ProbeSurfaceSeed::ensure($owner);
    }

    public function testOwnerPreviewLabelBackKeepsAsAndOpensInventory(): void
    {
        $seed = $this->seed();
        $owner = self::client();
        $owner->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $labels = VendorPreview::with('/' . self::SLUG . '/vendor/labels', $seed->vendorId);
        $printed = $owner->post($labels, [
            'item' => $seed->itemId,
            'copies' => '1',
            'stock' => 'avery_8160',
            'start_row' => '1',
            'start_column' => '1',
        ]);
        self::assertSame(200, $printed->status);
        self::assertSees('Print stickers', $printed);
        self::assertDoesNotMatchRegularExpression(
            '/This address is not a page in/',
            $printed->body
        );
        if (preg_match('/<a class="btn" href="([^"]+)">Back<\/a>/', $printed->body, $match) !== 1) {
            self::fail('stickers Back link was missing');
        }
        $back = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        self::assertStringContainsString('as=', $back);
        self::assertStringContainsString($seed->vendorId, $back);
        $inventory = $owner->get($back);
        self::assertSame(200, $inventory->status);
        self::assertSees('Your items', $inventory);
        self::assertDoesNotMatchRegularExpression(
            '/This address is not a page in/',
            $inventory->body
        );
    }

    public function testEveryRoleLinkGraphStaysOnKnownPages(): void
    {
        $seed = $this->seed();
        $slug = self::SLUG;
        $failures = [];

        $owner = self::client();
        $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $failures = array_merge(
            $failures,
            $this->label(
                'owner',
                (new PageSurfaceCrawler($owner, $slug, 'owner'))->crawlGets(['/' . $slug . '/dashboard'])
            )
        );

        $cashier = self::client();
        $cashier->signIn($slug, $seed->cashierUsername, $seed->cashierPassword);
        $failures = array_merge(
            $failures,
            $this->label(
                'cashier',
                (new PageSurfaceCrawler($cashier, $slug, 'cashier'))->crawlGets(['/' . $slug . '/dashboard'])
            )
        );

        $books = self::client();
        $books->signIn($slug, $seed->booksUsername, $seed->booksPassword);
        $failures = array_merge(
            $failures,
            $this->label(
                'books',
                (new PageSurfaceCrawler($books, $slug, 'books'))->crawlGets(['/' . $slug . '/dashboard'])
            )
        );

        $vendor = self::client();
        $vendor->signIn($slug, $seed->vendorUsername, $seed->vendorPassword);
        $failures = array_merge(
            $failures,
            $this->label(
                'vendor',
                (new PageSurfaceCrawler($vendor, $slug, 'vendor'))->crawlGets(['/' . $slug . '/vendor'])
            )
        );

        $preview = self::client();
        $preview->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $start = VendorPreview::with('/' . $slug . '/vendor', $seed->vendorId);
        $failures = array_merge(
            $failures,
            $this->label(
                'owner-preview',
                (new PageSurfaceCrawler($preview, $slug, 'owner', 35, $seed->vendorId))->crawlGets([$start])
            )
        );

        self::assertSame([], $failures, "broken surface links:\n" . implode("\n", $failures));
    }

    private function seed(): ProbeSurfaceSeed
    {
        $seed = self::$seed;
        if (!$seed instanceof ProbeSurfaceSeed) {
            self::fail('surface seed was not built');
        }

        return $seed;
    }

    /**
     * @param list<string> $failures
     * @return list<string>
     */
    private function label(string $role, array $failures): array
    {
        $labeled = [];
        foreach ($failures as $failure) {
            $labeled[] = $role . ': ' . $failure;
        }

        return $labeled;
    }
}
