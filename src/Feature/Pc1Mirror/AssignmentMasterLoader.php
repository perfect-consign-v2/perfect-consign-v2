<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AssignmentMasterLoader
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $vendors
     * @param array<string, string> $booths
     * @return array{loaded: int, skips: list<string>}
     */
    public function load(MirrorMall $mall, array $rows, array $vendors, array $booths): array
    {
        $count = 0;
        $skips = [];
        foreach ($rows as $row) {
            $pc1Booth = MirrorFields::text($row['pc1_booth_id'] ?? null);
            $pc1Vendor = MirrorFields::text($row['pc1_consignor_id'] ?? null);
            $booth = $booths[$pc1Booth] ?? null;
            $vendor = $vendors[$pc1Vendor] ?? null;
            if ($booth === null || $vendor === null) {
                $reason = 'missing booth or vendor map';
                $skips[] = $this->recordSkip($reason, $booth, $vendor, '', '', $pc1Booth, $pc1Vendor);
                continue;
            }
            $starts = MirrorFields::text($row['starts_on'] ?? null);
            $ends = MirrorFields::text($row['ends_on'] ?? null);
            $rent = MirrorFields::cents($row['rent_minor'] ?? 0);
            if (!$this->acceptsStay($starts, $ends, $rent)) {
                $skips[] = $this->recordSkip('rejected dates or rent', $booth, $vendor, $starts, $ends, $pc1Booth, $pc1Vendor);
                continue;
            }
            try {
                $mall->assignments->addAssignment($booth, $vendor, $starts, $ends, $rent);
            } catch (\Throwable $error) {
                $skips[] = $this->recordSkip(
                    $error->getMessage(),
                    $booth,
                    $vendor,
                    $starts,
                    $ends,
                    $pc1Booth,
                    $pc1Vendor
                );
                continue;
            }
            $count++;
        }

        return ['loaded' => $count, 'skips' => $skips];
    }

    private function recordSkip(
        string $reason,
        ?string $booth,
        ?string $vendor,
        string $starts,
        string $ends,
        string $pc1Booth,
        string $pc1Vendor,
    ): string {
        $detail = 'pc1_booth=' . $pc1Booth
            . ' pc1_vendor=' . $pc1Vendor
            . ' booth=' . ($booth ?? '')
            . ' vendor=' . ($vendor ?? '')
            . ' starts=' . $starts
            . ' ends=' . $ends
            . ' reason=' . $reason;
        $this->logger->warning('pc1 mirror assignment skipped', [
            'pc1_booth_id' => $pc1Booth,
            'pc1_vendor_id' => $pc1Vendor,
            'booth_id' => $booth,
            'vendor_id' => $vendor,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'reason' => $reason,
        ]);

        return $detail;
    }

    private function acceptsStay(string $starts, string $ends, int $rent): bool
    {
        if ($starts === '' || $rent < 1) {
            return false;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) !== 1) {
            return false;
        }
        if ($ends === '') {
            return true;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) === 1 && $ends >= $starts;
    }
}
