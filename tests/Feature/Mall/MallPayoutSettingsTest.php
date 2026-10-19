<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class MallPayoutSettingsTest extends TestCase
{
    public function testLateFeeGraceKeepsZeroAndTwentyEight(): void
    {
        self::assertSame(28, $this->settings($this->row(28, false))->lateFee()['grace']);
        self::assertSame(0, $this->settings($this->row(-1, false))->lateFee()['grace']);
        self::assertSame(0, $this->settings($this->row(0, false))->lateFee()['grace']);
    }

    public function testEachStoredFlagCountsAsOn(): void
    {
        foreach ([true, 1, '1', 't', 'true'] as $flag) {
            self::assertTrue($this->settings($this->row(0, $flag))->rentPrepaid());
        }
        self::assertFalse($this->settings($this->row(0, 'f'))->rentPrepaid());
        self::assertFalse($this->settings($this->row(0, 0))->rentPrepaid());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function settings(array $row): MallPayoutSettings
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new class ($pdo, $row) extends Statements {
            /**
             * @param array<string, mixed> $row
             */
            public function __construct(PDO $pdo, private readonly array $row)
            {
                parent::__construct($pdo);
            }

            public function rows(string $sql): array
            {
                return [$this->row];
            }
        };

        return new MallPayoutSettings($statements);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $grace, mixed $prepaid): array
    {
        return [
            'operating_mode' => 'both',
            'payout_cadence' => 'monthly',
            'rent_prepaid_from_prior_sales' => $prepaid,
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'late_fees_enabled' => false,
            'late_fee_grace_days' => $grace,
            'late_fee_first_minor' => 0,
            'late_fee_next_minor' => 0,
        ];
    }
}
