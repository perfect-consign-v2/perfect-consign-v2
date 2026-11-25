<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Scalar\RowValue;
use RuntimeException;

/**
 * Files one email a day to STALL_PLATFORM_ADMIN_EMAIL listing the probe bans
 * that started in the day before the summary hour (platform.probe_rules).
 * Nothing is filed on a day with no bans, or twice for the same day.
 */
final class ProbeBanDigestJob
{
    public const PURPOSE = 'probe-ban-digest';

    public const MOST_LISTED = 40;

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailOutbox $outbox,
    ) {
    }

    public function send(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new RuntimeException('job date is missing');
        }
        $to = PlatformOperatorNotice::adminAddress();
        if ($to === null) {
            return;
        }
        $rows = $this->bans($day);
        if ($rows === []) {
            return;
        }
        $subject = self::subject($day);
        if ($this->filed($subject)) {
            return;
        }
        $this->outbox->file('platform', MailOutbox::MAILBOX_PLATFORM, self::PURPOSE, $to, $subject, self::body($day, $rows));
    }

    public static function subject(string $day): string
    {
        return 'Perfect Consign: new probe bans for ' . $day;
    }

    /**
     * @param list<array{address: string, reason: string, strikes: int, path: string, banned_at: string, banned_until: string, time_zone: string}> $rows
     */
    public static function body(string $day, array $rows): string
    {
        $zone = $rows[0]['time_zone'] ?? '';
        $lines = [
            count($rows) === 1
                ? 'One address was banned in the day before the ' . $day . ' summary.'
                : count($rows) . ' addresses were banned in the day before the ' . $day . ' summary.',
            'Times are ' . $zone . '. Lift a ban in Admin, App log.',
        ];
        foreach (array_slice($rows, 0, self::MOST_LISTED) as $row) {
            $lines[] = '';
            $lines[] = $row['address'];
            $lines[] = 'Why: ' . self::reason($row['reason']);
            $lines[] = 'Ban number: ' . (string) $row['strikes'];
            $lines[] = 'Path: ' . $row['path'];
            $lines[] = 'Started: ' . $row['banned_at'];
            $lines[] = 'Ends: ' . $row['banned_until'];
        }
        $more = count($rows) - self::MOST_LISTED;
        if ($more > 0) {
            $lines[] = '';
            $lines[] = 'And ' . (string) $more . ' more. App log lists every ban.';
        }

        return implode("\n", $lines) . "\n";
    }

    private static function reason(string $reason): string
    {
        return match ($reason) {
            'streak' => 'too many probe requests',
            'scanner' => 'a scanning tool',
            default => $reason,
        };
    }

    private function filed(string $subject): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM platform.mail_outbox
             WHERE tenant = ? AND mailbox = ? AND purpose = ? AND subject = ?'
        );
        $statement->execute(['platform', MailOutbox::MAILBOX_PLATFORM, self::PURPOSE, $subject]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<array{address: string, reason: string, strikes: int, path: string, banned_at: string, banned_until: string, time_zone: string}>
     */
    private function bans(string $day): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM platform.probe_ban_summary(?::date)');
        $statement->execute([$day]);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'address' => RowValue::string($row['address'] ?? null),
                'reason' => RowValue::string($row['reason'] ?? null),
                'strikes' => RowValue::integer($row['strikes'] ?? null),
                'path' => RowValue::string($row['path'] ?? null),
                'banned_at' => RowValue::string($row['banned_at'] ?? null),
                'banned_until' => RowValue::string($row['banned_until'] ?? null),
                'time_zone' => RowValue::string($row['time_zone'] ?? null),
            ];
        }

        return $rows;
    }
}
