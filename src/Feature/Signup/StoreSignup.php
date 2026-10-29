<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Schema\TenantProvisioner;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class StoreSignup
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PlatformSettings $settings,
        private readonly SignupRateLimit $limits,
        private readonly AdminAudit $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{slug: string, username: string, email: string, verified: bool}
     */
    public function registerPublic(
        string $storeName,
        string $email,
        string $password,
        string $confirm,
        string $clientAddress,
    ): array {
        if (!$this->settings->storeSignupEnabled()) {
            throw new RuntimeException('Store signup is not available.');
        }
        if ($this->limits->refuse($clientAddress)) {
            throw new RuntimeException('Too many signup attempts. Try again later.');
        }
        $this->limits->remember($clientAddress);
        $verified = !$this->settings->requireEmailVerification();

        return $this->register(
            $storeName,
            $email,
            $password,
            $confirm,
            $verified,
            'store.signup',
            null,
            $email,
        );
    }

    /**
     * @return array{slug: string, username: string, email: string, verified: bool}
     */
    public function registerAdmin(
        string $storeName,
        string $email,
        string $password,
        string $confirm,
        string $adminId,
        string $adminEmail,
    ): array {
        return $this->register(
            $storeName,
            $email,
            $password,
            $confirm,
            true,
            'store.admin_create',
            $adminId,
            $adminEmail,
        );
    }

    /**
     * @return array{slug: string, username: string, email: string, verified: bool}
     */
    private function register(
        string $storeName,
        string $email,
        string $password,
        string $confirm,
        bool $emailVerified,
        string $auditAction,
        ?string $adminId,
        string $auditEmail,
    ): array {
        $storeName = trim($storeName);
        $email = strtolower(trim($email));
        if ($storeName === '' || strlen($storeName) > 120) {
            throw new RuntimeException('Enter a store name.');
        }
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        if ($password !== $confirm) {
            throw new RuntimeException('Password confirmation does not match.');
        }

        $slug = $this->slugFromName($storeName);
        if ($this->slugTaken($slug)) {
            throw new RuntimeException('That store address is already taken.');
        }
        $username = $this->usernameFromEmail($email);

        $admin = BackupConnection::admin()->pdo();
        $sqlDirectory = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql';
        $provisioner = new TenantProvisioner($admin, $sqlDirectory);
        try {
            $provisioner->provision($slug, $storeName);
            $this->createOwner($admin, $slug, $username, $password, $email, $emailVerified);
        } catch (Throwable $error) {
            $this->logger->error('store signup failed', [
                'slug' => $slug,
                'message' => $error->getMessage(),
            ]);
            $this->compensate($admin, $slug);
            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('That store could not be created.', 0, $error);
        }

        $tenantId = $this->tenantId($slug);
        $this->audit->record(
            $adminId,
            $auditEmail,
            $auditAction,
            'ok',
            $tenantId,
            $slug,
            ['display_name' => $storeName, 'email_verified' => $emailVerified],
        );

        return [
            'slug' => $slug,
            'username' => $username,
            'email' => $email,
            'verified' => $emailVerified,
        ];
    }

    public function slugFromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 's' . $slug;
        }
        if (strlen($slug) > 63) {
            $slug = substr($slug, 0, 63);
            $slug = rtrim($slug, '-');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new RuntimeException('Enter a store name that can become a web address.');
        }

        return $slug;
    }

    private function usernameFromEmail(string $email): string
    {
        $local = strstr($email, '@', true);
        if (!is_string($local) || $local === '') {
            throw new RuntimeException('Enter a valid email address.');
        }
        $base = strtolower($local);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'u' . $base;
        }
        if (strlen($base) > 63) {
            $base = substr($base, 0, 63);
            $base = rtrim($base, '-');
        }
        $candidate = $base;
        $suffix = 2;
        while ($this->usernameTaken($candidate)) {
            $tail = (string) $suffix;
            $cut = 63 - strlen($tail);
            if ($cut < 1) {
                throw new RuntimeException('That store could not be created.');
            }
            $candidate = substr($base, 0, $cut) . $tail;
            $suffix++;
            if ($suffix > 9999) {
                throw new RuntimeException('That store could not be created.');
            }
        }

        return $candidate;
    }

    private function slugTaken(string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);

        return $statement->fetchColumn() !== false;
    }

    private function usernameTaken(string $username): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM platform.owners WHERE username = ?');
        $statement->execute([$username]);

        return $statement->fetchColumn() !== false;
    }

    private function createOwner(
        PDO $admin,
        string $slug,
        string $username,
        string $password,
        string $email,
        bool $emailVerified,
    ): void {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $statement = $admin->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
             SELECT uuidv7(), id, ?, ?, ?, CASE WHEN ?::text = \'true\' THEN clock_timestamp() ELSE NULL END
             FROM platform.tenants
             WHERE slug = ?'
        );
        try {
            $statement->execute([$username, $hash, $email, $emailVerified ? 'true' : 'false', $slug]);
        } catch (PDOException $error) {
            throw new RuntimeException('That store could not be created.', 0, $error);
        }
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('That store was not found after provisioning.');
        }
    }

    private function compensate(PDO $admin, string $slug): void
    {
        try {
            $wipe = $admin->prepare('SELECT platform.wipe_tenant(?)');
            $wipe->execute([$slug]);
        } catch (Throwable $error) {
            $this->logger->error('store signup compensate failed', [
                'slug' => $slug,
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function tenantId(string $slug): string
    {
        $statement = $this->pdo->prepare('SELECT id::text FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);
        $id = $statement->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('That store was not found after provisioning.');
        }

        return $id;
    }

    public function ownerIdForSlug(string $slug): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id::text
             FROM platform.owners o
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE t.slug = ?'
        );
        $statement->execute([$slug]);
        $id = $statement->fetchColumn();

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function abandon(string $slug): void
    {
        try {
            $admin = BackupConnection::admin()->pdo();
            $this->compensate($admin, $slug);
        } catch (Throwable $error) {
            $this->logger->error('store signup abandon failed', [
                'slug' => $slug,
                'message' => $error->getMessage(),
            ]);
        }
    }
}
