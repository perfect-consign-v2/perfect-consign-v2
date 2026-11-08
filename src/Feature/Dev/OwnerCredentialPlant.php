<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use InvalidArgumentException;
use PDO;
use PDOException;
use PerfectConsign\Feature\Password\PasswordChoice;
use RuntimeException;

/**
 * Plants or updates a mall owner without bumping credentials_version when the
 * password already matches (sessions stay valid).
 */
final class OwnerCredentialPlant
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordChoice $passwords = new PasswordChoice(),
    ) {
    }

    public function plant(string $slug, string $username, string $password, ?string $email): OwnerPlantOutcome
    {
        if ($slug === '' || $username === '') {
            throw new InvalidArgumentException('slug and username are required');
        }
        $email = $this->normalizeEmail($email);
        $existing = $this->existingOwner($slug, $username);
        if ($existing !== null && $this->passwordMatches($password, $existing['password_hash'])) {
            return $this->touchWhenPasswordMatches($existing, $username, $email);
        }

        return $this->upsertOwner($slug, $username, $password, $email);
    }

    /**
     * @return array{id: string, username: string, password_hash: string, email: mixed}|null
     */
    private function existingOwner(string $slug, string $username): ?array
    {
        $current = $this->pdo->prepare(
            'SELECT o.id, o.username, o.password_hash, o.email
             FROM platform.owners o
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE t.slug = ? AND o.username = ?'
        );
        $current->execute([$slug, $username]);
        $existing = $current->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            return null;
        }
        $id = $existing['id'] ?? null;
        $storedUsername = $existing['username'] ?? null;
        $hash = $existing['password_hash'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($storedUsername) || !is_string($hash)) {
            throw new RuntimeException('the owner row is invalid');
        }

        return [
            'id' => $id,
            'username' => $storedUsername,
            'password_hash' => $hash,
            'email' => $existing['email'] ?? null,
        ];
    }

    /**
     * @param array{id: string, username: string, password_hash: string, email: mixed} $existing
     */
    private function touchWhenPasswordMatches(array $existing, string $username, ?string $email): OwnerPlantOutcome
    {
        if ($existing['username'] === $username && $this->emailsMatch($email, $existing['email'])) {
            return OwnerPlantOutcome::Unchanged;
        }
        $touch = $this->pdo->prepare(
            'UPDATE platform.owners
             SET username = ?,
                 email = COALESCE(?, email),
                 email_verified_at = COALESCE(email_verified_at, clock_timestamp())
             WHERE id = ?::uuid'
        );
        try {
            $touch->execute([$username, $email, $existing['id']]);
        } catch (PDOException $error) {
            $this->refuseDuplicateEmail($error);
        }

        return OwnerPlantOutcome::ProfileSaved;
    }

    private function upsertOwner(string $slug, string $username, string $password, ?string $email): OwnerPlantOutcome
    {
        $hash = $this->passwords->hash($password);
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
             SELECT uuidv7(), id, ?, ?, ?, clock_timestamp()
             FROM platform.tenants
             WHERE slug = ?
             ON CONFLICT (tenant_id, username) DO UPDATE
                SET password_hash = EXCLUDED.password_hash,
                    email = COALESCE(EXCLUDED.email, platform.owners.email),
                    email_verified_at = COALESCE(platform.owners.email_verified_at, clock_timestamp()),
                    credentials_version = platform.owners.credentials_version + 1'
        );
        try {
            $statement->execute([$username, $hash, $email, $slug]);
        } catch (PDOException $error) {
            $this->refuseDuplicateEmail($error);
        }
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('that mall was not found');
        }

        return OwnerPlantOutcome::Saved;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }
        $email = strtolower(trim($email));
        if (strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            throw new InvalidArgumentException('owner email is not an email address');
        }

        return $email;
    }

    private function passwordMatches(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    private function emailsMatch(?string $wanted, mixed $stored): bool
    {
        if ($wanted === null) {
            return true;
        }

        return is_string($stored) && strtolower($stored) === $wanted;
    }

    private function refuseDuplicateEmail(PDOException $error): never
    {
        $state = $error->errorInfo[0] ?? '';
        if ($state === '23505') {
            throw new RuntimeException('that email is already used on another sign-in', 0, $error);
        }
        throw $error;
    }
}
