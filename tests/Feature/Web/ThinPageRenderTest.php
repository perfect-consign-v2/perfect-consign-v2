<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class ThinPageRenderTest extends TestCase
{
    public function testTheWorkerPageShowsSetupAndRuns(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $missing = $this->capture($view, 'Feature/Admin/worker', [
            'csrf' => 'tok',
            'status' => [
                'password_configured' => false,
                'last_seen_at' => '',
                'last_jobs_finished' => 0,
                'last_jobs_failed' => 0,
                'waiting_mail' => 1,
                'overdue_jobs' => 2,
                'auto_prune_days' => 14,
            ],
            'runs' => [],
        ]);
        self::assertStringContainsString('STALL_WORKER_PASSWORD', $missing);
        self::assertStringContainsString('missing', $missing);
        self::assertStringContainsString('No job-bearing runs recorded yet.', $missing);

        $idle = $this->capture($view, 'Feature/Admin/worker', [
            'csrf' => 'tok',
            'status' => [
                'password_configured' => true,
                'last_seen_at' => '',
                'last_jobs_finished' => 0,
                'last_jobs_failed' => 0,
                'waiting_mail' => 0,
                'overdue_jobs' => 0,
                'auto_prune_days' => 14,
            ],
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
            'csrf' => 'tok',
            'status' => [
                'password_configured' => true,
                'last_seen_at' => '2026-11-12 01:00',
                'last_jobs_finished' => 4,
                'last_jobs_failed' => 0,
                'waiting_mail' => 0,
                'overdue_jobs' => 0,
                'auto_prune_days' => 14,
            ],
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
    }

    public function testTheSalesTaxPagePrintsAndLists(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $row = [
            'section' => 'sales',
            'label' => 'Taxable',
            'sale_count' => 2,
            'items_sold' => 3,
            'subtotal_minor' => 1000,
            'discount_minor' => 0,
            'tax_minor' => 80,
            'total_minor' => 1080,
        ];
        $filing = ['gross' => 1000, 'exempt' => 0, 'returns' => 0, 'taxable' => 1000, 'collected' => 80];
        $screen = $this->capture($view, 'Feature/Report/sales-tax', [
            'slug' => 'shop',
            'month' => '2026-11',
            'print' => false,
            'rows' => [$row],
            'filing' => $filing,
        ]);
        self::assertStringContainsString('Download CSV', $screen);
        self::assertStringContainsString('Taxable', $screen);
        $print = $this->capture($view, 'Feature/Report/sales-tax', [
            'slug' => 'shop',
            'month' => '2026-11',
            'print' => true,
            'rows' => [],
            'filing' => $filing,
        ]);
        self::assertStringNotContainsString('Download CSV', $print);
    }

    public function testTheCreateStorePageShowsAnError(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $blank = $this->capture($view, 'Feature/Admin/tenant-create', [
            'csrf' => 'tok',
            'error' => null,
            'storeName' => '',
            'email' => '',
        ]);
        self::assertStringNotContainsString('role="alert"', $blank);
        $failed = $this->capture($view, 'Feature/Admin/tenant-create', [
            'csrf' => 'tok',
            'error' => 'Enter a store name.',
            'storeName' => 'A',
            'email' => 'a@b.test',
        ]);
        self::assertStringContainsString('Enter a store name.', $failed);
    }

    public function testTheSignupPageShowsOffPendingAndTheForm(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $off = $this->capture($view, 'Feature/Signup/signup', [
            'csrf' => 'tok',
            'error' => null,
            'enabled' => false,
        ]);
        self::assertStringContainsString('turned off', $off);
        $pending = $this->capture($view, 'Feature/Signup/signup', [
            'csrf' => 'tok',
            'error' => null,
            'enabled' => true,
            'checkEmail' => true,
        ]);
        self::assertStringContainsString('Check your email', $pending);
        $form = $this->capture($view, 'Feature/Signup/signup', [
            'csrf' => 'tok',
            'error' => 'Enter a store name.',
            'enabled' => true,
            'checkEmail' => false,
        ]);
        self::assertStringContainsString('Enter a store name.', $form);
        self::assertStringContainsString('name="store_name"', $form);
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
