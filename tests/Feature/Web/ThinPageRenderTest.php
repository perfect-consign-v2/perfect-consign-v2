<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Tests\Support\AdminDoor;
use PHPUnit\Framework\TestCase;

final class ThinPageRenderTest extends TestCase
{
    public function testTheWorkerPageShowsSetupAndRuns(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $missing = $this->capture($view, 'Feature/Admin/worker', [
            'adminPath' => new AdminPath(AdminDoor::prefix()),
            'csrf' => 'tok',
            'status' => $this->workerStatus(false, '', '', false),
            'queue' => [],
            'givenUp' => [],
            'runs' => [],
        ]);
        self::assertStringContainsString('STALL_WORKER_PASSWORD', $missing);
        self::assertStringContainsString('missing', $missing);
        self::assertStringContainsString('No job-bearing runs recorded yet.', $missing);

        $idle = $this->capture($view, 'Feature/Admin/worker', [
            'adminPath' => new AdminPath(AdminDoor::prefix()),
            'csrf' => 'tok',
            'status' => $this->workerStatus(true, '', '', false),
            'queue' => [],
            'givenUp' => [],
            'runs' => [[
                'id' => '1',
                'worker' => 'mail',
                'started_at' => '2026-11-01 00:00',
                'finished_at' => '',
                'jobs_finished' => 3,
                'jobs_failed' => 1,
            ]],
        ]);
        self::assertStringContainsString('no heartbeat', $idle);
        self::assertStringContainsString('running', $idle);

        $seen = $this->capture($view, 'Feature/Admin/worker', [
            'adminPath' => new AdminPath(AdminDoor::prefix()),
            'csrf' => 'tok',
            'status' => $this->workerStatus(true, '2026-11-12 01:00', 'work', true),
            'queue' => [[
                'id' => '01900000-0000-7000-8000-00000000d203',
                'tenant' => 'webprobe',
                'kind' => 'vendor-letter',
                'run_at' => '2026-11-19 06:20:51+00',
                'finished_at' => '',
                'attempts' => 0,
                'error' => '',
                'subject' => 'Sweep 5cc961',
                'recipients' => '3a93a0@example.test',
                'transport' => 'site SMTP missing',
                'due' => true,
            ]],
            'givenUp' => [],
            'runs' => [[
                'id' => '2',
                'worker' => 'mail',
                'started_at' => '2026-11-12 01:00',
                'finished_at' => '2026-11-12 01:01',
                'jobs_finished' => 4,
                'jobs_failed' => 0,
            ]],
        ]);
        self::assertStringContainsString('2026-11-12 01:01', $seen);
        self::assertStringNotContainsString('no heartbeat', $seen);
        self::assertStringContainsString('Sweep 5cc961', $seen);
        self::assertStringContainsString('3a93a0@example.test', $seen);
        self::assertStringContainsString('site SMTP missing', $seen);
        self::assertStringContainsString('The worker last ran at 2026-11-12 01:00 as work.', $seen);
        self::assertStringContainsString('No jobs have been given up.', $seen);
    }

    /**
     * @return array{
     *     password_configured: bool,
     *     last_seen_at: string,
     *     last_worker: string,
     *     worker_stale: bool,
     *     last_jobs_finished: int,
     *     last_jobs_failed: int,
     *     waiting_mail: int,
     *     overdue_jobs: int,
     *     auto_prune_days: int,
     *     site_smtp: bool,
     *     platform_gmail: bool
     * }
     */
    private function workerStatus(bool $password, string $seen, string $worker, bool $stale): array
    {
        return [
            'password_configured' => $password,
            'last_seen_at' => $seen,
            'last_worker' => $worker,
            'worker_stale' => $stale,
            'last_jobs_finished' => 0,
            'last_jobs_failed' => 0,
            'waiting_mail' => 0,
            'overdue_jobs' => 0,
            'auto_prune_days' => 14,
            'site_smtp' => false,
            'platform_gmail' => false,
        ];
    }

    public function testTheSalesTaxPagePrintsAndLists(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $filing = ['gross' => 1000, 'exempt' => 0, 'returns' => 0, 'taxable' => 1000, 'collected' => 80];
        $screen = $this->capture($view, 'Feature/Report/sales-tax', [
            'slug' => 'shop',
            'label' => 'November 2026',
            'month' => '2026-11',
            'print' => false,
            'error' => null,
            'filing' => $filing,
            'rates' => [],
            'liability' => ['opening' => 0, 'collected' => 80, 'paid' => 0, 'closing' => 80],
            'headers' => ['Section'],
            'lines' => [['Taxable']],
            'download' => 'sales-tax.csv?month=2026-11',
        ]);
        self::assertStringContainsString('Download CSV', $screen);
        self::assertStringContainsString('Taxable', $screen);
        $print = $this->capture($view, 'Feature/Report/sales-tax', [
            'slug' => 'shop',
            'label' => '',
            'month' => '2026-11',
            'print' => true,
            'error' => null,
            'filing' => $filing,
            'rates' => [],
            'liability' => null,
            'headers' => [],
            'lines' => [],
            'download' => 'sales-tax.csv?month=2026-11',
        ]);
        self::assertStringNotContainsString('Download CSV', $print);
    }

    public function testTheCreateStorePageShowsAnError(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $blank = $this->capture($view, 'Feature/Admin/tenant-create', [
            'adminPath' => new AdminPath(AdminDoor::prefix()),
            'csrf' => 'tok',
            'error' => null,
            'storeName' => '',
            'email' => '',
        ]);
        self::assertStringNotContainsString('role="alert"', $blank);
        $failed = $this->capture($view, 'Feature/Admin/tenant-create', [
            'adminPath' => new AdminPath(AdminDoor::prefix()),
            'csrf' => 'tok',
            'error' => 'Enter a store name.',
            'storeName' => 'A',
            'email' => 'a@b.test',
        ]);
        self::assertStringContainsString('Enter a store name.', $failed);
    }

    public function testTheSignupPagesShowOffTheFormSentConfirmAndWait(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $off = $this->capture($view, 'Feature/Signup/signup', [
            'csrf' => 'tok',
            'error' => null,
            'enabled' => false,
        ]);
        self::assertStringContainsString('turned off', $off);
        self::assertStringNotContainsString('signup-proof.js', $off);
        $form = $this->capture($view, 'Feature/Signup/signup', [
            'csrf' => 'tok',
            'error' => 'Enter a store name.',
            'enabled' => true,
            'proof' => ['seed' => str_repeat('a', 32), 'bits' => 18],
            'storeName' => 'Harbor <Antiques>',
            'email' => 'owner@harbor.test',
        ]);
        self::assertStringContainsString('Enter a store name.', $form);
        self::assertStringContainsString('value="Harbor &lt;Antiques&gt;"', $form);
        self::assertStringContainsString('data-proof-seed="' . str_repeat('a', 32) . '"', $form);
        self::assertStringContainsString('data-proof-bits="18"', $form);
        self::assertStringContainsString('name="proof_nonce" value=""', $form);
        self::assertStringContainsString('disabled data-proof-button', $form);
        self::assertStringContainsString('signup-proof.js', $form);
        self::assertStringContainsString('<noscript>', $form);
        $sent = $this->capture($view, 'Feature/Signup/sent', ['sentence' => 'Check your email for a link.']);
        self::assertStringContainsString('Check your email for a link.', $sent);
        $confirm = $this->capture($view, 'Feature/Signup/verify', [
            'csrf' => 'tok',
            'token' => str_repeat('b', 43),
            'email' => 'owner@harbor.test',
        ]);
        self::assertStringContainsString('action="/signup/verify/' . str_repeat('b', 43) . '"', $confirm);
        self::assertStringContainsString('Set up my store', $confirm);
        $wait = $this->capture($view, 'Feature/Signup/wait', ['slow' => false]);
        self::assertStringContainsString('checks again on its own', $wait);
        $slow = $this->capture($view, 'Feature/Signup/wait', ['slow' => true]);
        self::assertStringContainsString('taking longer than it should', $slow);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(View $view, string $template, array $data): string
    {
        ob_start();
        $view->render($template, $data);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
