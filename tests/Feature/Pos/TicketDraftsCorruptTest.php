<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class TicketDraftsCorruptTest extends TestCase
{
    public function testCorruptAttributeJsonIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            [
                'id' => '01900000-0000-7000-8000-00000000d001',
                'draft_no' => 1,
                'vendor_id' => '01900000-0000-7000-8000-00000000d002',
                'vendor_label' => 'Ada',
                'description' => 'Lamp',
                'price_text' => '12.00',
                'brand_id' => '',
                'brand_label' => '',
                'discount_kind' => '',
                'discount_value' => '',
                'attributes_json' => '{not-json',
            ],
        ]);

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Instant Add attributes are corrupt');
        (new TicketDrafts($statements))->forSession('01900000-0000-7000-8000-00000000d000');
    }
}
