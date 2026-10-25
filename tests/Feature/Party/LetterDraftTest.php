<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Feature\Party\LetterDraft;
use PHPUnit\Framework\TestCase;

final class LetterDraftTest extends TestCase
{
    public function testASubjectAndMessageStayPlain(): void
    {
        $draft = new LetterDraft();

        self::assertSame('Pickup', $draft->subject('  Pickup  '));
        self::assertSame("Line one\nLine two", $draft->message("  Line one\nLine two  "));
    }

    public function testABlankSubjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter a subject.');
        (new LetterDraft())->subject('   ');
    }

    public function testASubjectStaysOnOneLine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter a subject on one line.');
        (new LetterDraft())->subject("Hello\nthere");
    }

    public function testALongSubjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The subject is too long.');
        (new LetterDraft())->subject(str_repeat('a', 151));
    }

    public function testABlankMessageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter a message.');
        (new LetterDraft())->message('   ');
    }

    public function testALongMessageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The message is too long.');
        (new LetterDraft())->message(str_repeat('a', 4001));
    }
}
