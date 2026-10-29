<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Shared\Mail\SealedSecret;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeServer;
use PHPUnit\Framework\TestCase;

final class ForgotMailboxKeyTest extends TestCase
{
    public function testAMissingMailKeyReturnsTheSameNotice(): void
    {
        $root = dirname(__DIR__, 2);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $saved = $this->mailbox($pdo);
        $savedKey = getenv('STALL_MAIL_KEY');
        $server = null;
        try {
            $this->storeGmail($pdo);
            $this->clearResetRequests($pdo);
            putenv('STALL_MAIL_KEY=missing');
            $server = ProbeServer::startWithoutMailKey($root);
            $guest = new FormClient($server->baseUrl());
            $guest->get('/forgot');
            $unknown = $guest->post('/forgot', ['email' => 'missing-user@example.com']);
            $outboxBefore = $this->outboxCount($pdo);
            $logBefore = $this->logSize($root);
            $known = $guest->post('/forgot', ['email' => ProbeMall::EMAIL]);
            self::assertSame(200, $unknown->status);
            self::assertSame(200, $known->status);
            self::assertStringContainsString(ResetNotice::SENT, $unknown->body);
            self::assertStringContainsString(ResetNotice::SENT, $known->body);
            self::assertStringNotContainsString('missing-user@example.com', $unknown->body);
            self::assertStringNotContainsString('missing-user@example.com', $known->body);
            self::assertSame($outboxBefore, $this->outboxCount($pdo), 'a reset must not be filed when the mailbox key is missing');
            self::assertStringContainsString('the reset mailbox could not be read', $this->logTail($root, $logBefore));
        } finally {
            if ($server !== null) {
                $server->stop();
            }
            $this->restore($pdo, $saved);
            if (is_string($savedKey) && $savedKey !== '') {
                putenv('STALL_MAIL_KEY=' . $savedKey);
            }
        }
    }

    private function connection(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function schema(PDO $pdo): string
    {
        $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([ProbeMall::SLUG]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('the probe mall schema is missing');
        }

        return $schema;
    }

    /**
     * @return array{via: string, address: ?string, password: ?string}
     */
    private function mailbox(PDO $pdo): array
    {
        $query = $pdo->query('SELECT receipt_mail_via, gmail_address, gmail_app_password FROM mall');
        if ($query === false) {
            self::fail('the mailbox could not be read');
        }
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['receipt_mail_via'] ?? null)) {
            self::fail('the mailbox could not be read');
        }
        $address = $row['gmail_address'] ?? null;
        $password = $row['gmail_app_password'] ?? null;

        return [
            'via' => $row['receipt_mail_via'],
            'address' => is_string($address) ? $address : null,
            'password' => is_string($password) ? $password : null,
        ];
    }

    private function storeGmail(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'UPDATE mall SET receipt_mail_via = ?, gmail_address = ?, gmail_app_password = ?'
        );
        $statement->execute(['gmail', 'shop@example.com', SealedSecret::seal('abcdefghijklmnop')]);
    }

    /**
     * @param array{via: string, address: ?string, password: ?string} $saved
     */
    private function restore(PDO $pdo, array $saved): void
    {
        $statement = $pdo->prepare(
            'UPDATE mall SET receipt_mail_via = ?, gmail_address = ?, gmail_app_password = ?'
        );
        $statement->execute([$saved['via'], $saved['address'], $saved['password']]);
    }

    private function clearResetRequests(PDO $pdo): void
    {
        $tenant = $pdo->prepare('SELECT id FROM platform.tenants WHERE slug = ?');
        $tenant->execute([ProbeMall::SLUG]);
        $id = $tenant->fetchColumn();
        if (!is_string($id) || $id === '') {
            self::fail('the probe mall is missing');
        }
        $clear = $pdo->prepare('DELETE FROM platform.password_reset_requests WHERE tenant_id = ?::uuid');
        $clear->execute([$id]);
    }

    private function outboxCount(PDO $pdo): int
    {
        $query = $pdo->query(
            "SELECT count(*) FROM platform.mail_outbox
             WHERE purpose = 'password reset mail was refused'
               AND address = " . $pdo->quote(strtolower(ProbeMall::EMAIL))
        );

        return $query === false ? 0 : (int) $query->fetchColumn();
    }

    private function logSize(string $root): int
    {
        $path = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'app.log';
        if (!is_file($path)) {
            return 0;
        }
        $size = filesize($path);

        return $size === false ? 0 : $size;
    }

    private function logTail(string $root, int $offset): string
    {
        $path = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'app.log';
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $text = stream_get_contents($handle);
        fclose($handle);

        return is_string($text) ? $text : '';
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is required');
        }

        return $value;
    }
}
