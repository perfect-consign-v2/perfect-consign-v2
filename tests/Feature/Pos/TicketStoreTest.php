<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class TicketStoreTest extends TestCase
{
    public function testABlankScanIsRefused(): void
    {
        try {
            $this->store($this->createStub(Statements::class))->scan('sess', '');
            self::fail('a blank scan was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('scan a barcode or a tag', $error->getMessage());
        }
    }

    public function testAMissingLineCannotChangePrice(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000t1']]);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('affected')->willReturn(0);
        try {
            $this->store($statements)->price('sess', 1, 100);
            self::fail('a missing line was repriced');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that item is not on the ticket', $error->getMessage());
        }
    }

    public function testAMissingLineCannotBeRemoved(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000t1']]);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('affected')->willReturn(0);
        try {
            $this->store($statements)->remove('sess', 1);
            self::fail('a missing line was removed');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that item is not on the ticket', $error->getMessage());
        }
    }

    public function testAClosedTicketCannotBePriced(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        try {
            $this->store($statements)->price('sess', 1, 100);
            self::fail('a closed ticket was priced');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
    }

    public function testAMallWithoutAClockCannotBuildACart(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([]);
        $mall = new ReflectionMethod(TicketStore::class, 'mall');
        try {
            $mall->invoke($this->store($statements));
            self::fail('an empty mall was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the mall has no clock', $error->getMessage());
        }
    }

    public function testAnEmptyOpenTicketIsReleased(): void
    {
        $deleted = false;
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'FROM tickets')) {
                return [['id' => '00000000-0000-7000-8000-0000000000t1']];
            }

            return [['lines' => 0]];
        });
        $statements->method('change')->willReturnCallback(static function () use (&$deleted): void {
            $deleted = true;
        });
        $release = new ReflectionMethod(TicketStore::class, 'releaseEmpty');
        $release->invoke($this->store($statements), 'sess');
        self::assertTrue($deleted);
    }

    public function testAMissingTicketIsLeftAlone(): void
    {
        $deleted = false;
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $statements->method('change')->willReturnCallback(static function () use (&$deleted): void {
            $deleted = true;
        });
        $release = new ReflectionMethod(TicketStore::class, 'releaseEmpty');
        $release->invoke($this->store($statements), 'sess');
        self::assertFalse($deleted);
    }

    public function testOneMatchingItemIsTheScannedItem(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['id' => 'item-1', 'price_minor' => 250],
        ]);
        $item = new ReflectionMethod(TicketStore::class, 'item');
        $found = $item->invoke($this->store($statements), 'TAG-1');
        self::assertIsArray($found);
        self::assertSame('item-1', $found['id']);
        self::assertSame(250, $found['price']);
    }

    public function testADuplicateScanIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['id' => 'a', 'price_minor' => 100],
            ['id' => 'b', 'price_minor' => 100],
        ]);
        $item = new ReflectionMethod(TicketStore::class, 'item');
        try {
            $item->invoke($this->store($statements), 'TAG-1');
            self::fail('a duplicate scan was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that scan matches more than one item', $error->getMessage());
        }
    }

    public function testALostQuantityUpdateIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['line_no' => 1]]);
        $statements->method('affected')->willReturn(0);
        $add = new ReflectionMethod(TicketStore::class, 'add');
        try {
            $add->invoke($this->store($statements), 'ticket', 'item', 100);
            self::fail('a lost quantity update was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that item is not on the ticket', $error->getMessage());
        }
    }

    private function store(Statements $statements): TicketStore
    {
        $store = (new ReflectionClass(TicketStore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TicketStore::class, 'statements'))->setValue($store, $statements);

        return $store;
    }
}
