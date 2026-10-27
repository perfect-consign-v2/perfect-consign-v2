<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

final class CoveringFilterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PC_MUTATE_FUNCTIONS=1');
        require_once dirname(__DIR__, 2) . '/bin/mutate.php';
        putenv('PC_MUTATE_FUNCTIONS');
    }

    public function testAMissingCoveringMapIsEmpty(): void
    {
        self::assertSame([], loadCoveringMap(dirname(__DIR__, 2) . '/var/mutation/missing-covering.json'));
    }

    public function testCoveringTestsAndFilterPreferTheLineOwners(): void
    {
        $covering = [
            'src\\Feature\\Rent\\RentFigure.php' => [
                47 => [
                    'PerfectConsign\\Tests\\Feature\\Rent\\RentFigureTest::testAStartOnTheFirst',
                    'PerfectConsign\\Tests\\Feature\\Rent\\RentBookTest::testAPinnedStay',
                ],
            ],
        ];
        $tests = coveringTests($covering, 'src/Feature/Rent/RentFigure.php', 47);
        self::assertCount(2, $tests);
        $filter = coveringFilter($tests);
        self::assertNotNull($filter);
        self::assertStringContainsString('RentFigureTest', $filter);
        self::assertStringContainsString('RentBookTest', $filter);
        self::assertNull(coveringFilter([]));
    }

    public function testCoveringHitsUsesAbsolutePaths(): void
    {
        $root = dirname(__DIR__, 2);
        $covering = [
            'src\\Ledger\\Money.php' => [
                19 => ['PerfectConsign\\Tests\\Ledger\\MoneyTest::testZero'],
            ],
        ];
        $hits = coveringHits($root, $covering);
        $file = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Ledger' . DIRECTORY_SEPARATOR . 'Money.php';
        self::assertSame(1, $hits[$file][19] ?? 0);
    }
}
