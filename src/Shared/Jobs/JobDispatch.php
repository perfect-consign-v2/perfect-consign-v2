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
        $decoded = json_decode($job['payload'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('job payload is not an object');
        }
        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('job payload is not an object');
            }
            $payload[$key] = $value;
        }
        match ($job['kind']) {
            'assess-late-fees' => $this->lateFees->lateFees($this->date($payload)),
            'vendor-digest' => $this->digest->digest($job['tenant'], $this->date($payload)),
            'rent-reminder' => $this->digest->remind($job['tenant'], $this->date($payload)),
            'reconcile-reports' => $this->reconcileDated($payload),
            'sale-notice' => $this->notices->notice($job['tenant'], $this->date($payload)),
            'vendor-letter' => $this->letters->send($this->letterId($payload)),
            'markdown-propose' => $this->markdowns->propose($this->date($payload)),
            'outbound-mail' => $this->outbound->send($this->mailId($payload)),
            default => throw new RuntimeException('unknown job'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reconcileDated(array $payload): void
    {
        $date = $this->date($payload);
        $this->reconcile->tie($date);
        $this->reconcile->reconcile();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function date(array $payload): string
    {
        $date = $payload['date'] ?? null;
        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException('job date is missing');
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function letterId(array $payload): string
    {
        $letter = $payload['letter'] ?? null;
        if (!is_string($letter) || $letter === '') {
            throw new RuntimeException('job letter is missing');
        }

        return $letter;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mailId(array $payload): string
    {
        $mail = $payload['mail'] ?? null;
        if (!is_string($mail) || $mail === '') {
            throw new RuntimeException('job mail is missing');
        }

        return $mail;
    }
}
