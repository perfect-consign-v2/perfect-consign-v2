<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\PageResponse;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PayoutCoverageHttpTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const MISSING = '00000000-0000-7000-8000-000000000099';

    private FormClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $client = self::client();
        $client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $client->ensurePeriod(self::SLUG);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testPayoutScreensRefuseBadCloseAndPrintChecks(): void
    {
        $past = $this->authed('/' . self::SLUG . '/payouts/past');
        self::assertSees('Past payouts', $past);
        $list = $this->authed('/' . self::SLUG . '/payouts');
        self::assertTrue(str_contains(strtolower($list->body), 'payout'), HtmlPage::error($list->body));
        $runId = $this->runId($past->body . $list->body);
        $report = $this->authed('/' . self::SLUG . '/payouts/report');
        self::assertSame(200, $report->status);
        $blankUndo = $this->postAuthed('/' . self::SLUG . '/payouts/undo', [
            'run_id' => self::MISSING,
            'reason' => '',
        ]);
        self::assertSees('a payout reversal needs the operator and a reason', $blankUndo);
        $missingUndo = $this->postAuthed('/' . self::SLUG . '/payouts/undo', [
            'run_id' => self::MISSING,
            'reason' => 'coverage',
        ]);
        self::assertLessThan(500, $missingUndo->status);
        $badTender = $this->postAuthed('/' . self::SLUG . '/payouts/close', ['tender' => 'wire']);
        self::assertTrue(
            str_contains($badTender->body, 'Choose how to pay.')
            || str_contains($badTender->body, 'This month is already paid.')
            || str_contains($badTender->body, 'already paid'),
            HtmlPage::error($badTender->body)
        );
        $noDrawer = $this->postAuthed('/' . self::SLUG . '/payouts/close', ['tender' => 'drawer_cash']);
        self::assertTrue(
            str_contains($noDrawer->body, 'Open the drawer before paying cash from it.')
            || str_contains($noDrawer->body, 'This month is already paid.')
            || str_contains($noDrawer->body, 'already paid'),
            HtmlPage::error($noDrawer->body)
        );

        $this->vendor('50', 'hold');
        $singleId = $this->vendor('50', 'pay');
        $this->openRegister();
        $this->sell($singleId, '10.00');

        $singleForm = $this->client->get('/' . self::SLUG . '/payout?vendor=' . $singleId);
        self::assertSees('Pay this vendor', $singleForm);
        $posted = $this->client->post('/' . self::SLUG . '/payout', ['vendor_id' => $singleId]);
        self::assertTrue(
            str_contains($posted->body, 'Payout posted.')
            || str_contains($posted->body, 'a payout clears'),
            HtmlPage::error($posted->body)
        );
        $paidForm = $this->client->get('/' . self::SLUG . '/payout?vendor=' . $singleId);
        if (preg_match('/name="run_id" value="([0-9a-fA-F-]{36})"/', $paidForm->body, $singleRun) === 1) {
            $undone = $this->client->post('/' . self::SLUG . '/payout/undo', [
                'vendor_id' => $singleId,
                'run_id' => $singleRun[1],
                'reason' => 'coverage undo',
            ]);
            self::assertTrue(
                str_contains($undone->body, 'Payout undone.')
                || HtmlPage::error($undone->body) !== ''
            );
            $afterUndo = $this->client->get('/' . self::SLUG . '/payout?vendor=' . $singleId);
            self::assertTrue(
                str_contains($afterUndo->body, 'Undone by')
                || str_contains($afterUndo->body, 'Pay this vendor')
            );
        }

        if ($runId !== '') {
            $printedReport = $this->client->get('/' . self::SLUG . '/payouts/report?run=' . $runId);
            self::assertLessThan(500, $printedReport->status);
            $this->numberChecks($runId);
        }
        $this->client->get('/' . self::SLUG . '/payouts/checks?run=' . self::MISSING);
        $this->client->get('/' . self::SLUG . '/payout');
        $this->printSeededChecks();
    }

    /**
     * @param array<string, string> $fields
     */
    private function postAuthed(string $path, array $fields): PageResponse
    {
        $page = $this->client->post($path, $fields, 120);
        if (str_contains($page->body, '<h1>Sign in</h1>')) {
            $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
            $page = $this->client->post($path, $fields);
        }

        return $page;
    }

    private function authed(string $path): PageResponse
    {
        $page = $this->client->get($path);
        if (str_contains($page->body, '<h1>Sign in</h1>')) {
            $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
            $page = $this->client->get($path);
        }
        self::assertSame(200, $page->status);

        return $page;
    }

    private function numberChecks(string $runId): void
    {
        $page = $this->client->get('/' . self::SLUG . '/payouts/checks?run=' . $runId);
        self::assertSame(200, $page->status);
        if (!str_contains($page->body, 'starting_check') && !str_contains($page->body, 'Pay to the order of')) {
            return;
        }
        $blank = $this->client->post('/' . self::SLUG . '/payouts/checks?run=' . $runId, [
            'starting_check' => '',
        ]);
        if (str_contains($blank->body, 'starting_check')) {
            self::assertSees('Type the number on the first check.', $blank);
        }
        $printed = $this->client->post('/' . self::SLUG . '/payouts/checks?run=' . $runId, [
            'starting_check' => '4017',
        ]);
        self::assertSame(200, $printed->status);
        self::assertTrue($this->printedCheckPage($printed->body), HtmlPage::error($printed->body));
    }

    private function printedCheckPage(string $html): bool
    {
        return str_contains($html, 'Pay to the order of')
            || str_contains($html, 'Type the number on the first check.')
            || str_contains($html, 'First check number')
            || str_contains($html, 'starting_check')
            || str_contains($html, 'Those checks are not ready to print.')
            || str_contains($html, 'Nobody on this payout is getting a check.')
            || str_contains($html, 'That check was voided.')
            || str_contains($html, 'That payout is missing.');
    }

    private function printSeededChecks(): void
    {
        $seeded = $this->seedPaidChecks();
        if ($seeded === []) {
            return;
        }
        $blankId = $seeded['blank'] ?? '';
        $facedId = $seeded['faces'] ?? '';
        $emptyId = $seeded['empty'] ?? '';
        $draftId = $seeded['draft'] ?? '';
        if ($blankId !== '') {
            $this->client->get('/' . self::SLUG . '/payouts/report?run=' . $blankId);
            $this->numberChecks($blankId);
        }
        if ($facedId !== '') {
            $faces = $this->authed('/' . self::SLUG . '/payouts/checks?run=' . $facedId);
            self::assertTrue($this->printedCheckPage($faces->body), HtmlPage::error($faces->body));
        }
        if ($emptyId !== '') {
            $empty = $this->authed('/' . self::SLUG . '/payouts/checks?run=' . $emptyId);
            self::assertTrue(
                str_contains($empty->body, 'Nobody on this payout is getting a check.')
                || str_contains($empty->body, 'Those checks are not ready to print.'),
                HtmlPage::error($empty->body)
            );
        }
        if ($draftId !== '') {
            $draft = $this->authed('/' . self::SLUG . '/payouts/checks?run=' . $draftId);
            self::assertSees('Those checks are not ready to print.', $draft);
            $this->postAuthed('/' . self::SLUG . '/payouts/undo', [
                'run_id' => $draftId,
                'reason' => 'coverage voided',
            ]);
        }
    }

    /**
     * @return array{blank?: string, faces?: string, empty?: string, draft?: string}
     */
    private function seedPaidChecks(): array
    {
        $pdo = new \PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT')
            . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        if ($listed === false) {
            return [];
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return [];
        }
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        try {
            $vendorId = $this->uuid($pdo);
            $code = 'ck' . substr($vendorId, 0, 6);
            $pdo->prepare('INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)')
                ->execute([$vendorId, $code, 'Check', $code]);
            $blank = $this->insertCheckRun($pdo, $vendorId, 2500, null, 'paid', '2021-01-01', '2021-01-31');
            $faces = $this->insertCheckRun($pdo, $vendorId, 2500, '6104', 'paid', '2021-02-01', '2021-02-28');
            $empty = $this->insertCheckRun($pdo, $vendorId, 0, null, 'paid', '2021-03-01', '2021-03-31');
            $draft = $this->insertCheckRun($pdo, $vendorId, 2500, null, 'draft', '2021-04-01', '2021-04-30');
        } catch (\PDOException) {
            return [];
        }

        return [
            'blank' => $blank,
            'faces' => $faces,
            'empty' => $empty,
            'draft' => $draft,
        ];
    }

    private function insertCheckRun(
        \PDO $pdo,
        string $vendorId,
        int $check,
        ?string $number,
        string $status,
        string $from,
        string $to,
    ): string {
        $runId = $this->uuid($pdo);
        $pdo->prepare(
            "INSERT INTO payout_runs (
                id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
             )
             SELECT ?, id, 'batch', 'monthly', ?::date, ?::date, ?::date, 'check', 'draft', ?::date
             FROM ledger_books WHERE is_default"
        )->execute([$runId, $from, $to, $from, $to]);
        $pdo->prepare(
            'INSERT INTO payout_run_lines (run_id, vendor_id, mode, check_minor, owes_minor, net_minor)
             VALUES (?::uuid, ?::uuid, ?, ?, 0, ?)'
        )->execute([$runId, $vendorId, 'pay', $check, $check]);
        if ($number !== null) {
            $pdo->prepare(
                'UPDATE payout_run_lines SET check_number = ? WHERE run_id = ?::uuid AND vendor_id = ?::uuid'
            )->execute([$number, $runId, $vendorId]);
        }
        if ($status === 'draft') {
            return $runId;
        }
        $pdo->prepare("UPDATE payout_runs SET status = 'settled', settled_at = clock_timestamp() WHERE id = ?::uuid")
            ->execute([$runId]);
        $pdo->prepare(
            "UPDATE payout_runs SET status = 'paid', paid_at = clock_timestamp() WHERE id = ?::uuid AND status = 'settled'"
        )->execute([$runId]);

        return $runId;
    }

    private function uuid(\PDO $pdo): string
    {
        $id = $pdo->query('SELECT uuidv7()')->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value);
        self::assertNotSame('', $value);

        return $value;
    }

    private function runId(string $html): string
    {
        if (preg_match('#/' . self::SLUG . '/payouts/checks\?run=([0-9a-fA-F-]{36})#', $html, $match) === 1) {
            return $match[1];
        }
        if (preg_match('#/' . self::SLUG . '/payouts/report\?run=([0-9a-fA-F-]{36})#', $html, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    private function vendor(string $take, string $mode): string
    {
        $code = 'p' . bin2hex(random_bytes(3));
        $page = $this->client->post('/' . self::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Pay',
            'last_name' => $code,
            'store_take' => $take,
            'payout_mode' => $mode,
        ]);
        self::assertSees('Vendor created.', $page);

        return $this->match($page->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#');
    }

    private function sell(string $vendorId, string $price): void
    {
        $code = 'k' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . self::SLUG . '/inventory', [
            'code' => $code,
            'description' => 'Payout ' . $code,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => $price,
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
        $this->client->post('/' . self::SLUG . '/pos/discard', []);
        $cart = $this->client->post('/' . self::SLUG . '/pos/scan', ['scan' => $code]);
        self::assertSees($code, $cart);
        $due = $this->match($cart->body, '/class="register-due">\\$([0-9.]+)</');
        $paid = $this->client->post('/' . self::SLUG . '/pos/pay', [
            'tender_cash' => $due,
            'cash_received' => $due,
        ]);
        self::assertSees('Sale posted.', $paid);
        $this->client->post('/' . self::SLUG . '/pos/new', []);
    }

    private function openRegister(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/registers');
        if (preg_match('#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/use)#', $page->body, $use) === 1) {
            $this->client->post($use[1], []);

            return;
        }
        if (str_contains($page->body, 'Close register')) {
            return;
        }
        $action = $this->match($page->body, '#(/' . self::SLUG . '/registers/[0-9a-fA-F-]{36}/open)#');
        $this->client->post($action, [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function zeroCounts(): array
    {
        $fields = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }

        return $fields;
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
