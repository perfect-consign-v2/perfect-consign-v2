<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Catalog;

use PerfectConsign\Feature\Catalog\DeletePrompt;
use PerfectConsign\Feature\Catalog\MergePrompt;
use PHPUnit\Framework\TestCase;

final class DeletePromptTest extends TestCase
{
    public function testDeleteAsksOnlyWhenTheNameCanGo(): void
    {
        self::assertSame('Delete this color?', DeletePrompt::sentence('color'));
        self::assertSame('Delete this brand?', DeletePrompt::sentence('brand'));
    }

    public function testMergeNamesTheItemsThatMove(): void
    {
        self::assertSame('Held will be removed.', MergePrompt::sentence(0, 'Held', null));
        self::assertSame(
            "1 item will show Levi's. Held will be removed.",
            MergePrompt::sentence(1, 'Held', "Levi's"),
        );
        self::assertSame(
            '3 items will show the name you choose. Held will be removed.',
            MergePrompt::sentence(3, 'Held', null),
        );
    }
}
