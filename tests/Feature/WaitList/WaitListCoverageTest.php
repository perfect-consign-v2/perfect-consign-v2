<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\WaitList;

use InvalidArgumentException;
use PerfectConsign\Feature\WaitList\WaitListFields;
use PerfectConsign\Feature\WaitList\WaitListStore;
use PerfectConsign\Shared\Database\Statements;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class WaitListCoverageTest extends TestCase
{
    public function testFieldsRefuseABadPerson(): void
    {
        $fields = new WaitListFields();
        try {
            $fields->create('Ada', 'Lane', 'ada@example.test', '555', '8', '', '');
            self::fail('one dimension was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('preferred size is whole feet', $error->getMessage());
        }
        try {
            $fields->create('Ada', 'Lane', 'ada@example.test', '555', '8.5', '10', '');
            self::fail('a fractional width was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('preferred size is whole feet', $error->getMessage());
        }
        try {
            $fields->update('Ada', 'Lane', 'ada@example.test', '555', '', '', 'lost', '');
            self::fail('a lost status was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that status is not on the wait list', $error->getMessage());
        }
        $created = $fields->create('Ada', 'Lane', 'ada@example.test', '555', '', '', '');
        self::assertNull($created['width']);
        $updated = $fields->update('Ada', 'Lane', 'ada@example.test', '555', '8', '10', 'placed', 'corner');
        self::assertSame('placed', $updated['status']);
    }

    public function testTheStorePresentsUnknownStatusAndWaitedDays(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            $this->row('Ada', 'Ada', 'waiting', 0, null),
            $this->row('Bea', 'Lane', 'contacted', 1, '8'),
            $this->row('Cy', 'Lane', 'mystery', 4, ''),
        ]);
        $statements->method('bound')->willReturn([['id' => '']]);
        $statements->method('affected')->willReturn(0);
        $logger = $this->createStub(LoggerInterface::class);
        $store = new WaitListStore($statements, new WaitListFields(), $logger);
        $entries = $store->entries('', 'all');
        self::assertSame('Ada', $entries[0]['name']);
        self::assertSame('Today', $entries[0]['waited']);
        self::assertSame('Any', $entries[0]['size']);
        self::assertSame('1 day', $entries[1]['waited']);
        self::assertSame('8 × 8 ft', $entries[1]['size']);
        self::assertSame('mystery', $entries[2]['status_label']);
        self::assertSame('4 days', $entries[2]['waited']);
        try {
            $store->add('Ada', 'Lane', 'ada@example.test', '555', '', '', '');
            self::fail('an empty wait list insert was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the wait list row was not created', $error->getMessage());
        }
        try {
            $store->save('00000000-0000-7000-8000-000000000001', 'Ada', 'Lane', 'ada@example.test', '555', '', '', 'waiting', '');
            self::fail('a missing wait list row was saved');
        } catch (InvalidArgumentException $error) {
            self::assertSame('That person is not on the wait list.', $error->getMessage());
        }
        try {
            $store->remove('00000000-0000-7000-8000-000000000001');
            self::fail('a missing wait list row was removed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('That person is not on the wait list.', $error->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $first, string $last, string $status, int $days, mixed $width): array
    {
        return [
            'id' => '00000000-0000-7000-8000-0000000000a1',
            'first_name' => $first,
            'last_name' => $last,
            'email' => 'ada@example.test',
            'phone' => '555',
            'width_ft' => $width,
            'depth_ft' => $width,
            'status' => $status,
            'notes' => null,
            'waiting_since' => '2026-10-01',
            'waited_days' => $days,
        ];
    }
}
