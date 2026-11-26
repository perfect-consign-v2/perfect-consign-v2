<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;

/**
 * Files the emails sent when an account reaches the account-wide sign-in
 * limit, once per window: to the store owner (or the admin), and to the
 * platform admin. The worker sends them through the platform mailbox.
 */
final class SignInAlert
{
    public const PURPOSE = 'sign-in-alert';

    public const OWNER_SUBJECT = 'Someone tried to sign in to your store many times';

    public const ADMIN_SUBJECT = 'Perfect Consign: an account reached the sign-in limit';

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailOutbox $outbox,
    ) {
    }

    public function send(SignInAccount $account): void
    {
        foreach ($this->owners($account) as $address) {
            $this->outbox->file('platform', MailOutbox::MAILBOX_PLATFORM, self::PURPOSE, $address, self::OWNER_SUBJECT, self::ownerBody($account));
        }
        $admin = PlatformOperatorNotice::adminAddress();
        if ($admin !== null) {
            $this->outbox->file('platform', MailOutbox::MAILBOX_PLATFORM, self::PURPOSE, $admin, self::ADMIN_SUBJECT, $this->adminBody($account));
        }
    }

    public static function ownerBody(SignInAccount $account): string
    {
        $what = $account->door === 'admin' ? 'the platform admin' : 'your store';

        return 'Someone tried to sign in to ' . $what . " many times.\n"
            . "If it wasn't you, there is nothing to do: you are still signed in on your devices.\n"
            . "To be safe, change your password.\n";
    }

    /**
     * @return list<string>
     */
    private function owners(SignInAccount $account): array
    {
        if ($account->door === 'admin') {
            return $account->email === null ? [] : [$account->email];
        }
        if ($account->tenantId === null) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT email FROM platform.owners WHERE tenant_id = ?::uuid AND email IS NOT NULL ORDER BY email'
        );
        $statement->execute([$account->tenantId]);

        return array_values(array_filter($statement->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    private function adminBody(SignInAccount $account): string
    {
        $lines = ['An account reached the sign-in limit and is refused from browsers it has not signed in from before.'];
        if ($account->door === 'admin') {
            $lines[] = 'Account: platform admin ' . (string) $account->email;
        } else {
            $lines[] = 'Store: ' . $this->slug((string) $account->tenantId);
            $lines[] = 'Sign-in name: ' . (string) $account->username;
        }
        $lines[] = 'Admin, Sign-ins lists every attempt.';

        return implode("\n", $lines) . "\n";
    }

    private function slug(string $tenantId): string
    {
        $statement = $this->pdo->prepare('SELECT slug FROM platform.tenants WHERE id = ?::uuid');
        $statement->execute([$tenantId]);
        $slug = $statement->fetchColumn();

        return is_string($slug) ? $slug : $tenantId;
    }
}
