<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Jobs;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Shared\Jobs\ProbeBanDigestJob;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class ProbeBanDigestTest extends TestCase
{
    private const DAY = '2099-03-02';

    private const QUIET_DAY = '2099-03-05';

    private const INSIDE = '203.0.113.91';

    private const BEFORE = '203.0.113.92';

    public function testTheSummaryListsTheDayBeforeTheHourOnceAndQuietDaysSendNothing(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $previous = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ban-digest@example.test');
        $this->clear($pdo);
        try {
            $this->plant($pdo, self::INSIDE, 'scanner', '1 hour');
            $this->plant($pdo, self::BEFORE, 'streak', '25 hours');
            $job = new ProbeBanDigestJob($pdo, new MailOutbox($pdo));

            $job->send(self::DAY);
            $job->send(self::DAY);
            $job->send(self::QUIET_DAY);

            $letters = $this->letters($pdo);
            self::assertCount(1, $letters);
            self::assertSame('ban-digest@example.test', $letters[0]['address']);
            self::assertSame(ProbeBanDigestJob::subject(self::DAY), $letters[0]['subject']);
            self::assertStringContainsString(self::INSIDE . "\nWhy: a scanning tool\n", $letters[0]['body']);
            self::assertStringNotContainsString(self::BEFORE, $letters[0]['body']);
        } finally {
            $this->clear($pdo);
            if (is_string($previous)) {
                putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $previous);
            } else {
                putenv('STALL_PLATFORM_ADMIN_EMAIL');
            }
        }
    }

    private function plant(PDO $pdo, string $address, string $reason, string $beforeTheHour): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO platform.probe_bans (client_address, banned_at, reason, user_agent, path, strikes, banned_until)
             SELECT ?, at_time, ?, 'browser', '/x.php', 1, at_time + interval '15 minutes'
             FROM (
                 SELECT ((?::date + make_interval(hours => rules.summary_hour)) AT TIME ZONE rules.summary_time_zone)
                        - ?::interval AS at_time
                 FROM platform.probe_rules AS rules
                 WHERE rules.id
             ) AS moment"
        );
        $statement->execute([$address, $reason, self::DAY, $beforeTheHour]);
    }

    /**
     * @return list<array{address: string, subject: string, body: string}>
     */
    private function letters(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            "SELECT address, subject, body FROM platform.mail_outbox
             WHERE tenant = 'platform' AND purpose = ? AND subject IN (?, ?) ORDER BY created_at"
        );
        $statement->execute($this->ours());
        $letters = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['address']);
            self::assertIsString($row['subject']);
            self::assertIsString($row['body']);
            $letters[] = ['address' => $row['address'], 'subject' => $row['subject'], 'body' => $row['body']];
        }

        return $letters;
    }

    /**
     * @return list<string>
     */
    private function ours(): array
    {
        return [
            ProbeBanDigestJob::PURPOSE,
            ProbeBanDigestJob::subject(self::DAY),
            ProbeBanDigestJob::subject(self::QUIET_DAY),
        ];
    }

    private function clear(PDO $pdo): void
    {
        $jobs = $pdo->prepare(
            "DELETE FROM platform.jobs
             WHERE tenant = 'platform' AND kind = 'outbound-mail'
               AND payload ->> 'mail' IN (
                   SELECT id::text FROM platform.mail_outbox
                   WHERE tenant = 'platform' AND purpose = ? AND subject IN (?, ?)
               )"
        );
        $jobs->execute($this->ours());
        $mail = $pdo->prepare(
            "DELETE FROM platform.mail_outbox WHERE tenant = 'platform' AND purpose = ? AND subject IN (?, ?)"
        );
        $mail->execute($this->ours());
        $bans = $pdo->prepare('DELETE FROM platform.probe_bans WHERE client_address IN (?, ?)');
        $bans->execute([self::INSIDE, self::BEFORE]);
    }
}
