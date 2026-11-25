<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use PerfectConsign\Feature\Admin\AdminPath;
use Psr\Log\LoggerInterface;

/**
 * How many probe hits start a ban, and for how long, are rules in
 * platform.probe_rules. Only requests shaped like a probe, and paths no
 * route knows, count. A missing record or a page someone's role cannot open
 * never counts. Someone signed in is never counted and never refused.
 */
final class ProbeShield
{
    public function __construct(
        private readonly ProbeBanStore $bans,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function blocked(Request $request, bool $signedIn): bool
    {
        $probe = AdminPath::isPublicAdmin($request->path) || ProbeShape::matches($request);
        if ($signedIn) {
            return $probe;
        }
        if ($request->address !== '' && $this->bans->banned($request->address)) {
            return true;
        }
        if (ScannerAgent::matches($request->trace->userAgent)) {
            $this->ban($request);

            return true;
        }
        if ($probe) {
            $this->hit($request);
        }

        return $probe;
    }

    public function finished(Request $request, int $status, bool $routeMissed, bool $signedIn): void
    {
        if ($signedIn || !$routeMissed || $status !== 404) {
            return;
        }
        $this->hit($request);
    }

    private function hit(Request $request): void
    {
        if ($request->address === '') {
            return;
        }
        if ($this->bans->hit($request->address, $request->trace->userAgent, $request->path)) {
            $this->logger->info('probe banned', MissLog::context($request, 'streak'));
        }
    }

    private function ban(Request $request): void
    {
        if ($request->address === '') {
            return;
        }
        if ($this->bans->insertBan($request->address, 'scanner', $request->trace->userAgent, $request->path)) {
            $this->logger->info('probe banned', MissLog::context($request, 'scanner'));
        }
    }
}
