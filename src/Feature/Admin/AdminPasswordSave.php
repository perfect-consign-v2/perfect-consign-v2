<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\SignInGate;
use PDO;

/**
 * Saves a new platform admin password: the hash, the next credentials
 * version, and the end of the sign-in count for that email.
 */
final class AdminPasswordSave
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SignInGate $gate,
    ) {
    }

    /**
     * @return string the new credentials version
     */
    public function save(string $adminId, string $email, string $hash): string
    {
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
        $this->gate->resetAdmin($email);

        return $version;
    }
}
