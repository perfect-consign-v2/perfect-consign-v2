<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\SmtpClient;
use PDO;
use Psr\Log\LoggerInterface;

final class AdminPasswordRecovery
{
    public const MAIL_FAILED = 'the reset email could not be sent';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminResetTokens $tokens,
        private readonly PasswordChoice $passwords,
        private readonly OutboundMail $mail,
        private readonly LoggerInterface $logger,
        private readonly AdminAudit $audit,
        private readonly AdminSession $session,
    ) {
    }

    public function request(string $email, string $origin): void
    {
        $email = strtolower(trim($email));
        if (
            preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1
            || strlen($email) > 200
            || !$this->allow($email)
        ) {
            return;
        }
        $row = $this->find($email);
        if ($row === null) {
            return;
        }
        $this->send($row, $origin);
        $this->audit->record($row['id'], $email, 'admin.password_reset_request', 'ok');
    }

    public function available(string $token): bool
    {
        return $this->tokens->available($token);
    }

    public function redeem(string $token, string $password, string $confirm): void
    {
        $this->pdo->beginTransaction();
        try {
            $locked = $this->tokens->lock($token);
            if ($locked === null) {
                throw new \InvalidArgumentException('That reset link expired. Request a new one.');
            }
            $row = $this->byId($locked['account']);
            if ($row === null) {
                throw new \InvalidArgumentException('That reset link expired. Request a new one.');
            }
            $hash = $this->passwords->replacement($row['hash'], $password, $confirm);
            $update = $this->pdo->prepare(
                'UPDATE platform.admins
                 SET password_hash = ?, credentials_version = credentials_version + 1
                 WHERE id = ?::uuid
                 RETURNING credentials_version'
            );
            $update->execute([$hash, $row['id']]);
            $version = $update->fetchColumn();
            if (is_int($version)) {
                $version = (string) $version;
            }
            if (!is_string($version) || $version === '') {
                throw new \RuntimeException('the platform admin password could not be saved');
            }
            $this->tokens->consume($locked['id']);
            $clear = $this->pdo->prepare('DELETE FROM platform.admin_sign_in_failures WHERE email = ?');
            $clear->execute([$row['email']]);
            $this->audit->record($row['id'], $row['email'], 'admin.password_reset', 'ok');
            $this->pdo->commit();
            $this->session->clear();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function change(string $adminId, string $current, string $password, string $confirm): string
    {
        $row = $this->byId($adminId);
        if ($row === null) {
            throw new \InvalidArgumentException('That password is wrong.');
        }
        $hash = $this->passwords->changed($current, $row['hash'], $password, $confirm);
        $update = $this->pdo->prepare(
            'UPDATE platform.admins
             SET password_hash = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid
             RETURNING credentials_version'
        );
        $update->execute([$hash, $adminId]);
        $version = $update->fetchColumn();
        if (is_int($version)) {
            $version = (string) $version;
        }
        if (!is_string($version) || $version === '') {
            throw new \RuntimeException('the platform admin password could not be saved');
        }
        $this->audit->record($adminId, $row['email'], 'admin.password_change', 'ok');
        $this->session->stamp($version);

        return $version;
    }

    private function allow(string $email): bool
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.admin_password_reset_requests
             WHERE email = ?
               AND requested_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email]);
        if ((int) $recent->fetchColumn() >= 5) {
            return false;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO platform.admin_password_reset_requests (email) VALUES (?)'
        );
        $insert->execute([$email]);

        return true;
    }

    /**
     * @return array{id: string, email: string, hash: string}|null
     */
    private function find(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, email, password_hash
             FROM platform.admins
             WHERE lower(email) = lower(?)'
        );
        $statement->execute([$email]);

        return $this->row($statement->fetch());
    }

    /**
     * @return array{id: string, email: string, hash: string}|null
     */
    private function byId(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, email, password_hash
             FROM platform.admins
             WHERE id = ?::uuid'
        );
        $statement->execute([$id]);

        return $this->row($statement->fetch());
    }

    /**
     * @return array{id: string, email: string, hash: string}|null
     */
    private function row(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $email = $row['email'] ?? null;
        $hash = $row['password_hash'] ?? null;
        if (!is_string($id) || !is_string($email) || !is_string($hash)) {
            return null;
        }

        return ['id' => $id, 'email' => $email, 'hash' => $hash];
    }

    /**
     * @param array{id: string, email: string, hash: string} $row
     */
    private function send(array $row, string $origin): void
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            $this->logger->error('admin reset link origin is missing');

            return;
        }
        $token = $this->tokens->issue($row['id']);
        $body = "A password reset was requested for the Perfect Consign platform admin.\n\n"
            . "Open this link within 30 minutes:\n"
            . $origin . '/admin/reset/' . $token . "\n\n"
            . "If you did not ask, you can ignore this message.\n";
        $gmail = $this->platformMailbox();
        $file = getenv('STALL_MAIL_FILE');
        try {
            if ($gmail === null || (is_string($file) && $file !== '')) {
                $this->mail->deliver($row['email'], 'Reset your password', $body);
            } else {
                (new SmtpClient(
                    $gmail['host'],
                    $gmail['port'],
                    $gmail['username'],
                    $gmail['password'],
                    $gmail['from'],
                ))->send($row['email'], 'Reset your password', $body);
            }
        } catch (\Throwable $error) {
            $this->logger->error('admin reset mail was refused', ['message' => $error->getMessage()]);
            throw new \RuntimeException(self::MAIL_FAILED, 0, $error);
        }
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, from: string}|null
     */
    private function platformMailbox(): ?array
    {
        $address = getenv('STALL_GMAIL_ADDRESS');
        $password = getenv('STALL_GMAIL_APP_PASSWORD');
        if (!is_string($address) || $address === '' || !is_string($password) || $password === '') {
            return null;
        }

        return [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => $address,
            'password' => $password,
            'from' => $address,
        ];
    }
}
