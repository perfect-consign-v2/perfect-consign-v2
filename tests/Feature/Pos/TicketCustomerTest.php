<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class TicketCustomerTest extends TestCase
{
    private const TICKET = '01900000-0000-7000-8000-0000000000t1';

    public function testBlankTicketIdsDoNotQueryTheDatabase(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->never())->method('bound');
        $customers = $this->customer($statements);

        self::assertSame(['id' => '', 'name' => ''], $customers->current(null));
        self::assertSame(['id' => '', 'name' => ''], $customers->current(''));
    }

    public function testMissingCustomerRowsReturnEmptyShopper(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $customers = $this->customer($statements);

        self::assertSame(['id' => '', 'name' => ''], $customers->current(self::TICKET));
    }

    public function testMalformedCustomerRowsReturnEmptyShopper(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['customer_id' => 12, 'name' => 'Ada']]);
        $customers = $this->customer($statements);

        self::assertSame(['id' => '', 'name' => ''], $customers->current(self::TICKET));
    }

    public function testKnownCustomerRowsReturnTheShopper(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'customer_id' => '01900000-0000-7000-8000-0000000000c1',
            'name' => 'Ada Lovelace',
        ]]);
        $customers = $this->customer($statements);

        self::assertSame([
            'id' => '01900000-0000-7000-8000-0000000000c1',
            'name' => 'Ada Lovelace',
        ], $customers->current(self::TICKET));
    }

    private function customer(Statements $statements): TicketCustomer
    {
        $customers = (new ReflectionClass(TicketCustomer::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TicketCustomer::class, 'statements'))->setValue($customers, $statements);
        (new ReflectionProperty(TicketCustomer::class, 'customers'))->setValue(
            $customers,
            (new ReflectionClass(CustomerStore::class))->newInstanceWithoutConstructor()
        );

        return $customers;
    }
}
