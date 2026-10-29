<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use RuntimeException;

final class JobDispatch
{
    public function __construct(
        private readonly LateFeeJob $lateFees,
        private readonly DigestJob $digest,
        private readonly ReconcileJob $reconcile,
        private readonly SaleNoticeJob $notices,
        private readonly VendorLetterJob $letters,
        private readonly MarkdownJob $markdowns,
        private readonly OutboundMailJob $outbound,
    ) {
    }

    /**
     * @param array{id: string, tenant: string, kind: string, payload: string, attempts: int} $job
     */
    public function dispatch(array $job): void
    {
        $payload = json_decode($job['payload'], true);
        $date = $this->date($payload);
        $tenant = $job['tenant'];
        $handlers = [
            'assess-late-fees' => function () use ($date): void {
                $this->lateFees->lateFees($date);
            },
            'vendor-digest' => function () use ($tenant, $date): void {
                $this->digest->digest($tenant, $date);
            },
            'rent-reminder' => function () use ($tenant, $date): void {
                $this->digest->remind($tenant, $date);
            },
            'reconcile-reports' => function () use ($date): void {
                $this->reconcile->tie($date);
                $this->reconcile->reconcile();
            },
            'sale-notice' => function () use ($tenant, $date): void {
                $this->notices->notice($tenant, $date);
            },
            'vendor-letter' => function () use ($payload): void {
                $this->letters->send($this->letterId($payload));
            },
            'markdown-propose' => function () use ($date): void {
                $this->markdowns->propose($date);
            },
            'outbound-mail' => function () use ($payload): void {
                $this->outbound->send($this->mailId($payload));
            },
        ];
        $handler = $handlers[$job['kind']] ?? null;
        if ($handler === null) {
            throw new RuntimeException('unknown job');
        }
        $handler();
    }

    private function date(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }
        $date = $payload['date'] ?? null;

        return is_string($date) ? $date : '';
    }

    private function letterId(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }
        $letter = $payload['letter'] ?? null;

        return is_string($letter) ? $letter : '';
    }

    private function mailId(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }
        $mail = $payload['mail'] ?? null;

        return is_string($mail) ? $mail : '';
    }
}
