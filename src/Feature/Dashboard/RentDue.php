<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dashboard;

use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Rent\RentBook;
use PerfectConsign\Feature\Rent\RentStore;

final class RentDue
{
    public function __construct(
        private readonly RentStore $rent,
        private readonly RentBook $book,
        private readonly MallPayoutSettings $settings,
    ) {
    }

    /**
     * @return array{month: string, rows: list<array<string, mixed>>}
     */
    public function open(): array
    {
        $today = $this->rent->today();
        $month = $this->book->month('', $today);
        $assembled = $this->book->assemble(
            $this->rent->facts($month),
            $month,
            $today,
            $this->settings->cycleDay(),
        );
        $rows = [];
        foreach ($assembled['rows'] as $row) {
            if ($row['open_minor'] > 0) {
                $rows[] = $row;
            }
        }

        return [
            'month' => is_string($assembled['month'] ?? null) ? $assembled['month'] : $month,
            'rows' => $rows,
        ];
    }
}
