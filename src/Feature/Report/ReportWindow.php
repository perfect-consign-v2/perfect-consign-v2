<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class ReportWindow
{
    private function __construct(
        public readonly ?string $error,
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly string $group,
        public readonly string $query,
    ) {
    }

    public static function ready(string $from, string $to, string $label, string $group, string $query): self
    {
        return new self(null, $from, $to, $label, $group, $query);
    }

    public static function failed(string $error, string $query): self
    {
        return new self($error, '', '', '', '', $query);
    }

    public function isFailed(): bool
    {
        return $this->error !== null;
    }
}
