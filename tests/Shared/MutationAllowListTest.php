<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

final class MutationAllowListTest extends TestCase
{
    public function testALinuxCoveragePathMatchesTheAllowList(): void
    {
        putenv('PC_MUTATE_FUNCTIONS=1');
        require_once dirname(__DIR__, 2) . '/bin/mutate.php';
        putenv('PC_MUTATE_FUNCTIONS');

        $linux = relative('/workspace/repo', '/workspace/repo/src/Feature/Rent/RentFigure.php');
        self::assertSame('src\\Feature\\Rent\\RentFigure.php', $linux);
        self::assertNotNull(equivalentReason($linux, 35, '&&', '||'));
        self::assertNotNull(equivalentReason($linux, 35, '>', '>='));

        $windows = relative(
            'C:\\laragon\\www\\perfect-consign',
            'C:\\laragon\\www\\perfect-consign\\src\\Feature\\Rent\\RentFigure.php'
        );
        self::assertSame('src\\Feature\\Rent\\RentFigure.php', $windows);
        self::assertNotNull(equivalentReason($windows, 35, '&&', '||'));
    }
}
