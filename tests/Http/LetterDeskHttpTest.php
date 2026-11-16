<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class LetterDeskHttpTest extends WebTestCase
{
    public function testLettersPageFilesAndReads(): void
    {
        $client = self::client();
        $slug = ProbeMall::SLUG;
        $client->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'email' => $code . '@example.test',
            'store_take' => '50',
        ]);
        self::assertSees('Vendor created.', $created);
        $vendorId = HtmlPage::pathId($created->body, $slug . '/vendors');
        $desk = $client->get('/' . $slug . '/letters');
        self::assertSees('Email', $desk);
        $subject = 'Pickup ' . $code;
        try {
            $filed = $client->post('/' . $slug . '/letters', [
                'subject' => $subject,
                'message' => 'Your items are ready.',
                'vendor_id[]' => $vendorId,
            ]);
            self::assertSees('The letter is filed', $filed);
            self::assertSees($subject, $filed);
        } finally {
            $this->removeLetter($subject);
        }
    }

    private function removeLetter(string $subject): void
    {
        $pdo = LocalAdmin::open()->pdo();
        $listed = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $listed->execute([ProbeMall::SLUG]);
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $find = $pdo->prepare('SELECT id::text FROM vendor_letters WHERE subject = ?');
        $find->execute([$subject]);
        $id = $find->fetchColumn();
        if (!is_string($id)) {
            return;
        }
        $job = $pdo->prepare(
            "DELETE FROM platform.jobs
             WHERE tenant = ? AND kind = 'vendor-letter' AND payload ->> 'letter' = ?"
        );
        $job->execute([ProbeMall::SLUG, $id]);
    }
}
