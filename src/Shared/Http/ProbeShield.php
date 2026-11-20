<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use PerfectConsign\Feature\Admin\AdminPath;
use Psr\Log\LoggerInterface;

final class ProbeShield
{
    public const STREAK_LIMIT = 2;

    public function __construct(
        private readonly ProbeBanStore $bans,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function blocked(Request $request): bool
    {
        if ($request->address !== '' && $this->bans->banned($request->address)) {
            return true;
        }
        if (ScannerAgent::matches($request->trace->userAgent)) {
            $this->ban($request, 'scanner');

            return true;
        }
        if (AdminPath::isPublicAdmin($request->path) || ProbeShape::matches($request)) {
            $this->streak($request);

            return true;
        }

        return false;
    }

    public function finished(Request $request, int $status): void
    {
        if ($request->address === '') {
            return;
        }
        if ($status === 200 || $status === 303) {
            $this->bans->clearStreak($request->address);

            return;
        }
        if ($status === 404) {
            $this->streak($request);
        }
    }

    private function streak(Request $request): void
    {
        if ($request->address === '') {
            return;
        }
        if ($this->bans->increment($request->address) < self::STREAK_LIMIT) {
            return;
        }
        $this->ban($request, 'streak');
    }

    private function ban(Request $request, string $reason): void
    {
        if ($request->address === '') {
            return;
        }
        if (!$this->bans->insertBan($request->address, $reason, $request->trace->userAgent, $request->path)) {
            return;
        }
        $this->logger->info('probe banned', MissLog::context($request, $reason));
    }
}
