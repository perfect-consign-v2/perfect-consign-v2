<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Admin\AdminSession;

/**
 * Password-proven admin who has not finished the authenticator step.
 * This is not a signed-in admin session.
 */
final class AdminMfaPending
{
    public const ID = 'platform_admin_mfa_id';

    public const EMAIL = 'platform_admin_mfa_email';

    public const AT = 'platform_admin_mfa_at';

    public const KIND = 'platform_admin_mfa_kind';

    public const SECRET = 'platform_admin_mfa_secret';

    public const CODES = 'platform_admin_mfa_codes';

    private const ENROLL_SECONDS = 600;

    private const CHALLENGE_SECONDS = 300;

    public function __construct(
        private readonly Session $session,
        private readonly AdminSession $admins,
    ) {
    }

    public function beginEnrollment(string $id, string $email): void
    {
        $this->begin($id, $email, 'enroll');
        $this->session->set(self::SECRET, Base32::encode(Totp::generateSecret()));
    }

    public function beginChallenge(string $id, string $email): void
    {
        $this->begin($id, $email, 'challenge');
    }

    public function target(int $now): ?string
    {
        $kind = $this->kind($now);
        if ($kind === null) {
            return null;
        }
        if ($kind === 'challenge') {
            return '/mfa';
        }
        if ($this->codes() !== null) {
            return '/mfa/codes';
        }

        return '/mfa/enroll';
    }

    /**
     * @return array{id: string, email: string, secret: string, grouped: string}|null
     */
    public function enrollment(int $now): ?array
    {
        if ($this->kind($now) !== 'enroll') {
            return null;
        }
        $id = $this->text(self::ID);
        $email = $this->text(self::EMAIL);
        $secret = $this->text(self::SECRET);
        if ($id === null || $email === null || $secret === null) {
            return null;
        }

        return [
            'id' => $id,
            'email' => $email,
            'secret' => $secret,
            'grouped' => Base32::groups($secret),
        ];
    }

    /**
     * @return array{id: string, email: string}|null
     */
    public function challenge(int $now): ?array
    {
        if ($this->kind($now) !== 'challenge') {
            return null;
        }
        $id = $this->text(self::ID);
        $email = $this->text(self::EMAIL);
        if ($id === null || $email === null) {
            return null;
        }

        return ['id' => $id, 'email' => $email];
    }

    /**
     * @param list<string> $codes
     */
    public function rememberCodes(array $codes): void
    {
        $this->session->set(self::CODES, $codes);
    }

    /**
     * @return list<string>|null
     */
    public function codes(): ?array
    {
        $value = $this->session->get(self::CODES);
        if (!is_array($value) || $value === []) {
            return null;
        }
        $codes = [];
        foreach ($value as $code) {
            if (!is_string($code) || $code === '') {
                return null;
            }
            $codes[] = $code;
        }

        return $codes;
    }

    private function begin(string $id, string $email, string $kind): void
    {
        $this->session->regenerateId(true);
        $this->admins->clear();
        $this->session->set(self::ID, $id);
        $this->session->set(self::EMAIL, $email);
        $this->session->set(self::AT, (string) time());
        $this->session->set(self::KIND, $kind);
        $this->session->set('csrf', bin2hex(random_bytes(16)));
    }

    private function kind(int $now): ?string
    {
        $kind = $this->text(self::KIND);
        $issued = $this->text(self::AT);
        if ($kind === null || $issued === null || ctype_digit($issued) !== true) {
            return null;
        }
        if ($kind !== 'enroll' && $kind !== 'challenge') {
            return null;
        }
        $limit = $kind === 'enroll' ? self::ENROLL_SECONDS : self::CHALLENGE_SECONDS;
        $age = $now - (int) $issued;
        if ($age < 0 || $age >= $limit) {
            $this->session->set('admin_warning', 'That sign-in expired. Sign in again.');
            $this->admins->clear();

            return null;
        }

        return $kind;
    }

    private function text(string $key): ?string
    {
        $value = $this->session->get($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
