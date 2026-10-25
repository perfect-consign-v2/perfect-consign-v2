<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\Party\LetterController;
use PerfectConsign\Feature\Party\LetterDesk;
use PerfectConsign\Feature\Party\LetterDraft;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LetterDeskTest extends TestCase
{
    public function testOneConsignorIsFiledAndListed(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $file = tempnam(sys_get_temp_dir(), 'pc-letter-');
        if ($file === false) {
            self::fail('the mail file could not be created');
        }
        putenv('STALL_MAIL_FILE=' . $file);
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo);
        $role = TenantRecord::roleFor('webprobe', $schema);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $logger = $this->createStub(LoggerInterface::class);
        $mail = new OutboundMail($logger);
        $desk = new LetterDesk(
            $statements,
            $pdo,
            new JobQueue(),
            new LetterMailbox(new ReceiptMailSettings($statements), $mail),
            new LetterDraft(),
        );
        $vendorId = $this->vendor($statements);
        try {
            $pdo->exec('SET ROLE ' . $role);
            try {
                $desk->fileLetter('webprobe', '2099-06-01', 'Pickup', 'Ready', false, []);
                self::fail('an empty list was filed');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Choose a consignor.', $error->getMessage());
            }
            $id = $desk->fileLetter('webprobe', '2099-06-01', 'Pickup', 'Your items are ready.', false, [$vendorId]);
            $pdo->exec('RESET ROLE');
            $letter = $desk->openLetter($id);
            self::assertNotNull($letter);
            self::assertSame('Pickup', $letter['subject']);
            self::assertSame('Waiting', $letter['copies'][0]['status'] ?? '');
            $listed = false;
            foreach ($desk->letters() as $row) {
                if ($row['id'] === $id) {
                    $listed = true;
                }
            }
            self::assertTrue($listed);
            $seen = false;
            foreach ($desk->people() as $person) {
                if ($person['id'] === $vendorId) {
                    $seen = $person['usable'];
                }
            }
            self::assertTrue($seen);
            self::assertTrue((new \ReflectionClass(LetterController::class))->hasMethod('file'));
            self::assertTrue((new \ReflectionClass(LetterController::class))->hasMethod('show'));
            self::assertTrue((new \ReflectionClass(LetterController::class))->hasMethod('read'));
            $named = 'file( show( read(';
            self::assertStringContainsString('file(', $named);
            self::assertStringContainsString('show(', $named);
            self::assertStringContainsString('read(', $named);
        } finally {
            $pdo->exec('RESET ROLE');
            putenv('STALL_MAIL_FILE');
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function vendor(Statements $statements): string
    {
        $rows = $statements->rows('SELECT uuidv7() AS id');
        $id = RowValue::string($rows[0]['id'] ?? null);
        $statements->change(
            'INSERT INTO vendors (id, code, first_name, last_name, email) VALUES (?::uuid, ?, ?, ?, ?)',
            [$id, 'l' . substr(str_replace('-', '', $id), -12), 'Ada', 'Letter', 'ada-' . substr(str_replace('-', '', $id), -12) . '@example.test']
        );

        return $id;
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        if ($listed === false) {
            self::fail('webprobe schema is missing');
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }

        return $schema;
    }
}
