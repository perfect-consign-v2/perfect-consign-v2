<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectConsign\Bootstrap\PostedRefusal;
use PerfectConsign\Ledger\DatabaseRefusal;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PostedRefusalTest extends TestCase
{
    public function testADatabaseLineIsTurnedIntoASentence(): void
    {
        $previous = new \RuntimeException('SQLSTATE detail');
        $error = new DatabaseRefusal('there are not enough on hand', 0, $previous);

        self::assertSame('That tag is already sold.', (new PostedRefusal(new NullLogger()))->phrase($error));
    }

    public function testAPlainMessagePassesThrough(): void
    {
        self::assertSame(
            'Choose a vendor.',
            (new PostedRefusal(new NullLogger()))->phrase(new \InvalidArgumentException('Choose a vendor.')),
        );
    }
}
