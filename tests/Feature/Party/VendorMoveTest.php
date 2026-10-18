<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\VendorMove;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class VendorMoveTest extends TestCase
{
    public function testAMoveNeedsADateAndTheNextBooth(): void
    {
        $move = new VendorMove($this->createStub(Statements::class));
        $vendor = '01900000-0000-7000-8000-0000000000a1';
        $stay = '01900000-0000-7000-8000-0000000000b1';
        $booth = '01900000-0000-7000-8000-0000000000c1';
        try {
            $move->move($vendor, $stay, $booth, 'soon', 1000);
            self::fail('a blank date was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter the move-out date.', $error->getMessage());
        }
        try {
            $move->move($vendor, $stay, 'next-booth', '2026-09-30', 1000);
            self::fail('a missing booth was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose the next booth.', $error->getMessage());
        }
    }
}
