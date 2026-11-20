<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Jobs;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class JobAttentionTest extends TestCase
{
    public function testAFiledLetterShowsItsSubjectAndRecipient(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schemaStatement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $schemaStatement->execute([ProbeMall::SLUG]);
        $schema = $schemaStatement->fetchColumn();
        self::assertIsString($schema);
        self::assertSame(1, preg_match('/^tenant_[0-9a-f]{32}$/', $schema));
        $party = '01900000-0000-7000-8000-00000000d204';
        $vendor = '01900000-0000-7000-8000-00000000d201';
        $letter = '01900000-0000-7000-8000-00000000d202';
        $job = '01900000-0000-7000-8000-00000000d203';
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET LOCAL search_path TO ' . $schema . ', pg_catalog, extensions');
            $this->change($pdo, 'INSERT INTO parties (id) VALUES (?::uuid)', [$party]);
            $this->change(
                $pdo,
                'INSERT INTO persons (party_id, first_name, last_name) VALUES (?::uuid, ?, ?)',
                [$party, 'Queue', 'Attention']
            );
            $this->change(
                $pdo,
                "INSERT INTO party_roles (party_id, role_type, status) VALUES (?::uuid, 'vendor', 'active')",
                [$party]
            );
            $this->change(
                $pdo,
                'INSERT INTO party_contacts (party_id, method, label, value, is_primary) VALUES (?::uuid, ?, ?, ?, true)',
                [$party, 'email', 'primary', 'ada@example.test']
            );
            $this->change(
                $pdo,
                'INSERT INTO vendors (id, party_id, code) VALUES (?::uuid, ?::uuid, ?)',
                [$vendor, $party, 'qattn']
            );
            $this->change(
                $pdo,
                'INSERT INTO vendor_letters (id, subject, body) VALUES (?::uuid, ?, ?)',
                [$letter, 'Queue attention', 'Hello from the queue.']
            );
            $this->change(
                $pdo,
                'INSERT INTO vendor_letter_copies (letter_id, vendor_id, email) VALUES (?::uuid, ?::uuid, ?)',
                [$letter, $vendor, 'ada@example.test']
            );
            $this->change(
                $pdo,
                "INSERT INTO platform.jobs (id, tenant, kind, payload, run_at)
                 VALUES (
                     ?::uuid,
                     ?,
                     'vendor-letter',
                     jsonb_build_object('date', '2026-11-18'::text, 'letter', ?::text),
                     clock_timestamp()
                 )",
                [$job, ProbeMall::SLUG, $letter]
            );
            $found = $pdo->prepare('SELECT subject, recipients, mail_via FROM platform.job_attention(?) WHERE id = ?');
            $found->execute(['open', $job]);
            $row = $found->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertSame('Queue attention', $row['subject']);
            self::assertSame('ada@example.test', $row['recipients']);
            self::assertSame('server', $row['mail_via']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function change(PDO $pdo, string $sql, array $params): void
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
    }
}
