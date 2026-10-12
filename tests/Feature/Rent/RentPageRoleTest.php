<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class RentPageRoleTest extends TestCase
{
    public function testTheLateFeeFormIsOwnerOnly(): void
    {
        $owner = $this->page(['actorRole' => 'owner', 'rows' => [$this->booth()]]);
        self::assertStringContainsString('action="/demo/rent/late-fee"', $owner);
        self::assertStringContainsString('Previous', $owner);
        self::assertStringNotContainsString('Not charged', $owner);
        self::assertStringContainsString('Post late fee', $owner);

        $cashier = $this->page(['actorRole' => 'cashier', 'rows' => [$this->booth()]]);
        self::assertStringNotContainsString('late-fee', $cashier);

        $missing = $this->page(['rows' => [$this->booth()]]);
        self::assertStringNotContainsString('late-fee', $missing);

        $empty = $this->page(['actorRole' => 'owner', 'rows' => []]);
        self::assertStringNotContainsString('late-fee', $empty);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function page(array $overrides): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Rent/rent', $overrides + [
            'slug' => 'demo',
            'csrf' => 'token',
            'month' => '2026-10',
            'label' => 'October 2026',
            'previous' => '2026-09',
            'next' => '2026-11',
            'today' => '2026-10-10',
            'query' => '',
            'standing' => '',
            'total' => 0,
            'matched' => 0,
            'counts' => ['due' => 0, 'charged' => 0, 'owed' => 0],
            'stats' => [
                'due_minor' => 0,
                'due_count' => 0,
                'charged_minor' => 0,
                'charged_count' => 0,
                'owed_minor' => 0,
                'owed_count' => 0,
            ],
            'rows' => [],
            'fromSales' => false,
            'debtors' => [],
            'backlog' => [],
            'activeSessionId' => '',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @return array{id: string, code: string}
     */
    private function booth(): array
    {
        return [
            'id' => '00000000-0000-7000-8000-000000000004',
            'code' => 'A1',
        ];
    }
}
