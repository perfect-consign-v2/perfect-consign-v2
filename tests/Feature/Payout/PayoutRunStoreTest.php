<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PDO;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class PayoutRunStoreTest extends TestCase
{
    public function testAMissingRunHasNoTenderOrStatus(): void
    {
        $store = $this->store([]);

        self::assertSame([
            'tender' => '',
            'status' => '',
            'posting_date' => '',
            'rent_month' => '',
        ], $store->run('00000000-0000-7000-8000-000000000099'));
    }

    public function testASavedRunReturnsItsTenderAndStatus(): void
    {
        $store = $this->store([[
            'tender' => 'check',
            'status' => 'paid',
            'posting_date' => '2026-10-01',
            'rent_month' => '2026-10-01',
        ]]);

        self::assertSame([
            'tender' => 'check',
            'status' => 'paid',
            'posting_date' => '2026-10-01',
            'rent_month' => '2026-10-01',
        ], $store->run('00000000-0000-7000-8000-000000000020'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function store(array $rows): PayoutRunStore
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class($pdo, $rows) extends Statements {
            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(PDO $pdo, private readonly array $rows)
            {
                parent::__construct($pdo);
            }

            /**
             * @param list<mixed> $params
             * @return list<array<string, mixed>>
             */
            public function bound(string $sql, array $params): array
            {
                return $this->rows;
            }
        };

        return new PayoutRunStore($statements, new OccupiedPack($statements));
    }
}
