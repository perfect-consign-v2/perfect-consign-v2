<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\WaitList;

use InvalidArgumentException;
use PDO;
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
        $email = str_repeat('a', 50) . '@' . str_repeat('b', 147) . '.c';
        self::assertSame(200, strlen($email));
        self::assertSame($email, $fields->create('Ada', 'Lane', $email, str_repeat('5', 40), '', '', str_repeat('n', 2000))['email']);
        self::assertNull($fields->create('Ada', 'Lane', 'ada@example.test', '555', '', '', '   ')['notes']);
        try {
            $fields->create('Ada', 'Lane', 'not-an-email', '555', '', '', '');
            self::fail('a word was accepted as an email');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that email is not an email address', $error->getMessage());
        }
        try {
            $fields->create('Ada', 'Lane', $email . 'x', '555', '', '', '');
            self::fail('a 201 character email was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that email is not an email address', $error->getMessage());
        }
        try {
            $fields->create('Ada', 'Lane', 'ada@example.test', str_repeat('5', 41), '', '', '');
            self::fail('a 41 character phone was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that phone is too long', $error->getMessage());
        }
        try {
            $fields->create('Ada', 'Lane', 'ada@example.test', '555', '', '', str_repeat('n', 2001));
            self::fail('notes past 2000 characters were accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('those notes are too long', $error->getMessage());
        }
    }

    public function testTheStorePresentsUnknownStatusAndWaitedDays(): void
    {
        $statements = $this->recording([
            $this->row('Ada', 'Ada', 'waiting', 0, null),
            $this->row('Bea', 'Lane', 'contacted', 1, '8'),
            $this->row('Cy', 'Lane', 'mystery', 4, ''),
        ]);
        $logger = $this->createStub(LoggerInterface::class);
        $store = new WaitListStore($statements, new WaitListFields(), $logger);
        $entries = $store->entries('', 'all');
        self::assertSame('Ada', $entries[0]['name']);
        self::assertSame('Today', $entries[0]['waited']);
        self::assertSame('Any', $entries[0]['size']);
        self::assertStringNotContainsString('WHERE', $statements->sql);
        self::assertSame('1 day', $entries[1]['waited']);
        self::assertSame('8 × 8 ft', $entries[1]['size']);
        self::assertSame('mystery', $entries[2]['status_label']);
        self::assertSame('4 days', $entries[2]['waited']);
        $open = $this->recording([]);
        (new WaitListStore($open, new WaitListFields(), $logger))->entries('ada', 'nope');
        self::assertStringContainsString("entry.status IN ('waiting', 'contacted')", $open->sql);
        $one = $this->recording([$this->sizeRow(null, 5), $this->sizeRow('', 5), $this->sizeRow(4, null)]);
        $sizes = (new WaitListStore($one, new WaitListFields(), $logger))->entries('', 'waiting');
        self::assertSame('Any', $sizes[0]['size']);
        self::assertSame('Any', $sizes[1]['size']);
        self::assertSame('Any', $sizes[2]['size']);
        self::assertStringContainsString('entry.status = ?', $one->sql);
        try {
            $store->add('Ada', 'Lane', 'ada@example.test', '555', '', '', '');
            self::fail('an empty wait list insert was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the wait list row was not created', $error->getMessage());
        }
        $numeric = $this->recording([]);
        $numeric->insertId = 4;
        try {
            (new WaitListStore($numeric, new WaitListFields(), $logger))->add('Ada', 'Lane', 'ada@example.test', '555', '', '', '');
            self::fail('a numeric wait list id was accepted');
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
     * @param list<array<string, mixed>> $rows
     */
    private function recording(array $rows): WaitListRecorder
    {
        return new WaitListRecorder($this->createStub(PDO::class), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function sizeRow(mixed $width, mixed $depth): array
    {
        $row = $this->row('Ada', 'Lane', 'waiting', 2, $width);
        $row['depth_ft'] = $depth;

        return $row;
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

final class WaitListRecorder extends Statements
{
    public string $sql = '';

    public mixed $insertId = '';

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(PDO $pdo, private readonly array $listed)
    {
        parent::__construct($pdo);
    }

    public function rows(string $sql): array
    {
        $this->sql = $sql;

        return $this->listed;
    }

    public function bound(string $sql, array $params): array
    {
        $this->sql = $sql;
        if (str_contains($sql, 'INSERT')) {
            return [['id' => $this->insertId]];
        }

        return $this->listed;
    }

    public function affected(string $sql, array $params): int
    {
        return 0;
    }
}
