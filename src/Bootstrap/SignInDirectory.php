<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PDO;

final class SignInDirectory
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{slug: string, username: string}|null
     */
    public function find(string $email): ?array
    {
        $email = $this->normalize($email);
        if ($email === null) {
            return null;
        }
        if ($this->pdo->exec('RESET ROLE') === false) {
            throw new \RuntimeException('the database role could not be reset');
        }
        $statement = $this->pdo->prepare(
            'SELECT tenants.slug, directory.username
             FROM platform.sign_in_directory AS directory
             INNER JOIN platform.tenants AS tenants ON tenants.id = directory.tenant_id
             WHERE lower(directory.email) = lower(?)'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $slug = $row['slug'] ?? null;
        $username = $row['username'] ?? null;
        if (!is_string($slug) || !is_string($username) || $slug === '' || $username === '') {
            return null;
        }

        return ['slug' => $slug, 'username' => $username];
    }

    public function normalize(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            return null;
        }

        return $email;
    }
}
