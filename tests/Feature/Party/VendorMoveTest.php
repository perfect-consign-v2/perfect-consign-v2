<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PDO;
use PerfectConsign\Feature\Party\VendorMove;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class VendorMoveTest extends TestCase
{
    public function testAMoveNeedsADateAndABooth(): void
    {
        $move = new VendorMove(new Statements($this->createStub(PDO::class)));

        try {
            $move->move(
                '00000000-0000-7000-8000-000000000001',
                '00000000-0000-7000-8000-000000000002',
                '00000000-0000-7000-8000-000000000003',
                'tomorrow',
                1000,
            );
            self::fail('a move without a date was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter the move-out date.', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose the next booth.');
        $move->move(
            '00000000-0000-7000-8000-000000000001',
            '00000000-0000-7000-8000-000000000002',
            'booth',
            '2026-10-01',
            1000,
        );
    }
}
