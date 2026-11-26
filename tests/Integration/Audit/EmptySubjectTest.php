<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Audit;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class EmptySubjectTest extends TestCase
{
    public function testAMissingAccountRollsTheBusinessWriteBack(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $listed = $pdo->query(
            "SELECT schema_name, id::text FROM platform.tenants WHERE slug = '" . ProbeMall::SLUG . "'"
        );
        if ($listed === false) {
            self::fail('webprobe is missing');
        }
        $tenant = $listed->fetch();
        if (!is_array($tenant) || !is_string($tenant['schema_name'] ?? null) || !is_string($tenant['id'] ?? null)) {
            self::fail('webprobe is missing');
        }
        $pdo->exec('SET search_path TO ' . $tenant['schema_name'] . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $before = $statements->rows('SELECT receipt_footer FROM mall');
        $prior = is_string($before[0]['receipt_footer'] ?? null) ? $before[0]['receipt_footer'] : '';
        $marker = 'empty-subject-' . bin2hex(random_bytes(4));
        $savedSession = $_SESSION ?? null;
        $pdo->beginTransaction();
        try {
            $statements->change('UPDATE mall SET receipt_footer = ?', [$marker]);
            QuietAudit::make($statements, new Session([], false))->record('settings.edit', 'company', $tenant['id']);
            self::fail('an empty session stored an activity row');
        } catch (DatabaseRefusal $refusal) {
            self::assertSame('the signed-in account is missing', $refusal->getMessage());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($savedSession === null) {
                $_SESSION = [];
            } else {
                $_SESSION = $savedSession;
            }
        }
        $after = $statements->rows('SELECT receipt_footer FROM mall');
        $footer = is_string($after[0]['receipt_footer'] ?? null) ? $after[0]['receipt_footer'] : '';
        self::assertSame($prior, $footer);
        self::assertNotSame($marker, $footer);
    }
}
