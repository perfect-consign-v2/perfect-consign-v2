<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PerfectConsign\Feature\Party\LetterDesk;
use PerfectConsign\Feature\Party\LetterDraft;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PHPUnit\Framework\TestCase;

final class LetterDeskCoverageTest extends TestCase
{
    private const VENDOR = '01900000-0000-7000-8000-0000000000a1';

    private const LETTER = '01900000-0000-7000-8000-0000000000b1';

    public function testLettersCanBeFiledForEveryoneOrOnePerson(): void
    {
        $desk = $this->desk(true, [
            ['id' => self::VENDOR, 'email' => 'ada@example.test'],
        ], [self::VENDOR => ['id' => self::VENDOR, 'email' => 'ada@example.test']]);

        self::assertSame(self::LETTER, $desk->fileLetter('zcov', '2099-06-01', ' Hello ', ' Body ', true, []));
        self::assertSame(self::LETTER, $desk->fileLetter('zcov', '2099-06-01', 'Hello', 'Body', false, [self::VENDOR, self::VENDOR]));
    }

    public function testFilingRefusesABadMallAMissingMailboxAndABadList(): void
    {
        $closed = $this->desk(false, [], []);
        try {
            $closed->fileLetter('Z', '2099-06-01', 'Hello', 'Body', false, []);
            self::fail('a bad mall was filed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the mall is not a mall', $error->getMessage());
        }
        try {
            $closed->fileLetter('zcov', '2099-06-01', 'Hello', 'Body', true, []);
            self::fail('mail was filed before it was set up');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Mail is not set up.', $error->getMessage());
        }

        $empty = $this->desk(true, [], []);
        try {
            $empty->fileLetter('zcov', '2099-06-01', 'Hello', 'Body', true, []);
            self::fail('an empty mall was filed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('No one has an email address.', $error->getMessage());
        }
        try {
            $empty->fileLetter('zcov', '2099-06-01', 'Hello', 'Body', false, []);
            self::fail('an empty choice was filed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Choose a consignor.', $error->getMessage());
        }
        try {
            $empty->fileLetter('zcov', '2099-06-01', 'Hello', 'Body', false, ['not-a-person']);
            self::fail('a bad consignor was filed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('That consignor is not on the list.', $error->getMessage());
        }
        try {
            $empty->fileLetter('zcov', '2099-06-01', 'Hello', 'Body', false, [self::VENDOR]);
            self::fail('a missing consignor was filed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('That consignor is not on the list.', $error->getMessage());
        }
    }

    public function testPeopleLettersAndALetterShowEveryStatus(): void
    {
        $desk = $this->desk(true, [], []);
        $people = $desk->people();
        self::assertSame('No email address', $people[0]['reason']);
        self::assertFalse($people[0]['usable']);
        self::assertSame('Bounced', $people[1]['reason']);
        self::assertTrue($people[2]['usable']);
        self::assertSame(2, $desk->letters()[0]['copies']);
        self::assertNull($desk->openLetter('nope'));
        self::assertNull($desk->openLetter('01900000-0000-7000-8000-0000000000ff'));

        $open = $desk->openLetter(self::LETTER);
        self::assertNotNull($open);
        self::assertSame(['Sent', 'Bounced', 'Waiting'], array_column($open['copies'], 'status'));
    }

    /**
     * @param list<array{id: string, email: string}> $eligible
     * @param array<string, array{id: string, email: string}> $chosen
     */
    private function desk(bool $ready, array $eligible, array $chosen): LetterDesk
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('change');
        $statements->method('rows')->willReturnCallback(function (string $sql) use ($eligible): array {
            if (str_contains($sql, 'uuidv7')) {
                return [['id' => self::LETTER]];
            }
            if (str_contains($sql, 'NOT vendor.email_bounced')) {
                return $eligible;
            }
            if (str_contains($sql, 'vendor_letters')) {
                return [[
                    'id' => self::LETTER,
                    'subject' => 'Hello',
                    'filed_on' => '2099-06-01',
                    'copies' => 2,
                ]];
            }

            return [
                ['id' => self::VENDOR, 'name' => 'Ada', 'email' => '', 'email_bounced' => false],
                ['id' => self::VENDOR, 'name' => 'Bea', 'email' => 'bea@example.test', 'email_bounced' => 't'],
                ['id' => self::VENDOR, 'name' => 'Cy', 'email' => 'cy@example.test', 'email_bounced' => false],
            ];
        });
        $statements->method('bound')->willReturnCallback(function (string $sql, array $params) use ($chosen): array {
            if (str_contains($sql, 'vendor_letter_copies')) {
                return [
                    ['name' => 'Ada', 'email' => 'ada@example.test', 'sent_at' => '2099-06-02', 'email_bounced' => false],
                    ['name' => 'Bea', 'email' => 'bea@example.test', 'sent_at' => null, 'email_bounced' => true],
                    ['name' => 'Cy', 'email' => 'cy@example.test', 'sent_at' => '', 'email_bounced' => 0],
                ];
            }
            if (str_contains($sql, 'FROM vendor_letters WHERE')) {
                if (($params[0] ?? '') !== self::LETTER) {
                    return [];
                }

                return [['subject' => 'Hello', 'body' => 'Body', 'filed_on' => '2099-06-01']];
            }
            if (str_contains($sql, 'vendor.id IN')) {
                return array_values($chosen);
            }

            return [];
        });
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $mailbox = $this->createStub(LetterMailbox::class);
        $mailbox->method('ready')->willReturn($ready);

        return new LetterDesk($statements, $pdo, new JobQueue(), $mailbox, new LetterDraft());
    }
}
