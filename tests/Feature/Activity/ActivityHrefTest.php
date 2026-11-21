<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Activity;

use PerfectConsign\Feature\Activity\ActivityHref;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class ActivityHrefTest extends TestCase
{
    private const ITEM = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private const JOURNAL = 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private const TICKET = 'cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public function testOpenFollowsTheRoleAndTheRecord(): void
    {
        $href = new ActivityHref($this->createStub(Statements::class));
        $journal = $this->row('journal', 'sale.void', self::JOURNAL);
        $deleted = $this->row('item', 'item.delete', self::ITEM);
        $edited = $this->row('item', 'item.edit', self::ITEM);
        $seller = $this->row('vendor', 'vendor.edit', self::ITEM);

        self::assertSame(
            '/shop/journals/' . self::JOURNAL,
            $href->apply('shop', 'books', 'vendors', [$journal])[0]['href'],
        );
        self::assertSame('', $href->apply('shop', 'books', 'vendors', [$edited])[0]['href']);
        self::assertSame('', $href->apply('shop', 'owner', 'vendors', [$deleted])[0]['href']);
        self::assertSame(
            '/shop/inventory/' . self::ITEM . '/edit',
            $href->apply('shop', 'manager', 'vendors', [$edited])[0]['href'],
        );
        self::assertSame(
            '/shop/consignors/' . self::ITEM,
            $href->apply('shop', 'owner', 'consignors', [$seller])[0]['href'],
        );
        self::assertSame('', $href->apply('shop', 'books', 'vendors', [$seller])[0]['href']);
    }

    public function testATicketOpensItsReceiptOnlyWhenOneJournalMatches(): void
    {
        $one = $this->createStub(Statements::class);
        $one->method('bound')->willReturn([
            ['ticket_id' => self::TICKET, 'journal_id' => self::JOURNAL],
        ]);
        $ticket = $this->row('ticket', 'sale.pay', self::TICKET);
        $href = new ActivityHref($one);

        self::assertSame(
            '/shop/pos/receipt/' . self::JOURNAL,
            $href->apply('shop', 'books', 'vendors', [$ticket])[0]['href'],
        );

        $two = $this->createStub(Statements::class);
        $two->method('bound')->willReturn([
            ['ticket_id' => self::TICKET, 'journal_id' => self::JOURNAL],
            ['ticket_id' => self::TICKET, 'journal_id' => self::ITEM],
        ]);

        self::assertSame('', (new ActivityHref($two))->apply('shop', 'owner', 'vendors', [$ticket])[0]['href']);
    }

    public function testASellerPathMustBeVendorsOrConsignors(): void
    {
        $href = new ActivityHref($this->createStub(Statements::class));
        $this->expectException(\InvalidArgumentException::class);
        $href->apply('shop', 'owner', 'people', [$this->row('vendor', 'vendor.edit', self::ITEM)]);
    }

    /**
     * @return array{id: string, action: string, entity_type: string, entity_id: string}
     */
    private function row(string $type, string $action, string $id): array
    {
        return [
            'id' => 'row-' . $type,
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
        ];
    }
}
