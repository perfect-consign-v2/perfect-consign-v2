<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostingFunctions;

/**
 * A sale paid the wrong booth. The database moves that piece's vendor share
 * to the vendor who should have been paid; PHP says which sale, which piece
 * and which vendor, and asks for the second signature.
 */
final class BoothCorrection
{
    public function __construct(
        private readonly PostingFunctions $postings,
        private readonly SaleCeiling $ceiling,
    ) {
    }

    public function move(
        string $date,
        string $journalId,
        string $itemId,
        string $toVendorId,
        string $overrideUser = '',
        string $overridePassword = '',
    ): string {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $toVendorId) !== 1) {
            throw new DatabaseRefusal('Choose the booth that should have been paid.');
        }

        return $this->postings->post(
            $date,
            'stall_booth_correction(?::uuid, ?::uuid, ?::uuid, ?::date)',
            [$journalId, $itemId, $toVendorId, $date],
            beforeCommit: function () use ($journalId, $overrideUser, $overridePassword): void {
                $this->ceiling->second('booth.correct', $journalId, $overrideUser, $overridePassword);
            },
        );
    }
}
