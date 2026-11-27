<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Every posted text field is trimmed once, here, as it is read. Forms and features do not
 * trim again, so a field of only blank space reaches them as ''. Passwords are read raw,
 * because a space is a real password character.
 */
final class RequestFieldTest extends TestCase
{
    public function testAPostedFieldIsTrimmedOfBlankSpaceOnBothEnds(): void
    {
        $request = new Request('POST', '/demo/pos/instant', [], [
            'description' => "  Lamp\t ",
            'price' => "\n12.00\r\n",
            'quantity' => " \x0B3 ",
            'blank' => " \t\n ",
        ]);

        self::assertSame('Lamp', $request->field('description'));
        self::assertSame('12.00', $request->field('price'));
        self::assertSame('3', $request->field('quantity'));
        self::assertSame('', $request->field('blank'));
        self::assertSame('', $request->field('absent'));
    }

    public function testAListPostedAsAFieldReadsAsBlank(): void
    {
        $request = new Request('POST', '/demo/pos/instant', [], ['description' => ['Lamp']]);

        self::assertSame('', $request->field('description'));
    }

    public function testAPasswordIsReadExactlyAsTyped(): void
    {
        $request = new Request('POST', '/demo/staff', [], ['password' => ' pass word ']);

        self::assertSame(' pass word ', $request->raw('password'));
        self::assertSame('pass word', $request->field('password'));
    }
}
