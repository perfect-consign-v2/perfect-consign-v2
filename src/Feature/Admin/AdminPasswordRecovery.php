<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Http\Request;
use PDO;

final class AdminPasswordRecovery
{
    public const MAIL_FAILED = 'the reset email could not be sent';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly AdminResetTokens $tokens,
        private readonly PasswordChoice $passwords,
        private readonly AdminResetMail $mail,
        private readonly AdminAudit $audit,
        private readonly AdminSession $session,
        private readonly LoginSession $tenants,
        private readonly AdminPasswordSave $saved,
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
        $this->mail->send($row, $origin);
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
            $this->saved->save($row['id'], $row['email'], $this->passwords->replacement($row['hash'], $password, $confirm));
            $this->tokens->consume($locked['id']);
            $this->audit->record($row['id'], $row['email'], 'admin.password_reset', 'ok');
            $this->pdo->commit();
            $this->tenants->clear();
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
        $version = $this->saved->save($adminId, $row['email'], $this->passwords->changed($current, $row['hash'], $password, $confirm));
        $this->audit->record($adminId, $row['email'], 'admin.password_change', 'ok');
        $this->session->stamp($version);

        return $version;
    }

    private function allow(string $email): bool
    {
        $address = $this->request->address;
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT pg_advisory_xact_lock(87221, hashtext(?)), pg_advisory_xact_lock(87222, hashtext(?))'
            );
            $lock->execute([$email, $address]);
            $this->pdo->exec(
                "DELETE FROM platform.admin_password_reset_requests
                 WHERE requested_at <= clock_timestamp() - interval '15 minutes'"
            );
            $recent = $this->pdo->prepare(
                "SELECT count(*)
                 FROM platform.admin_password_reset_requests
                 WHERE email = ?
                   AND requested_at > clock_timestamp() - interval '15 minutes'"
            );
            $recent->execute([$email]);
            if ((int) $recent->fetchColumn() >= 5) {
                $this->pdo->commit();

                return false;
            }
            $spray = $this->pdo->prepare(
                "SELECT count(*)
                 FROM platform.admin_password_reset_requests
                 WHERE client_address = ?
                   AND requested_at > clock_timestamp() - interval '15 minutes'"
            );
            $spray->execute([$address]);
            if ((int) $spray->fetchColumn() >= 40) {
                $this->pdo->commit();

                return false;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.admin_password_reset_requests (email, client_address) VALUES (?, ?)'
            );
            $insert->execute([$email, $address]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
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
}
