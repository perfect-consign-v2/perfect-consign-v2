<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use InvalidArgumentException;

final class RequestId
{
    public function __construct(public readonly string $value)
    {
        if ($this->value === '' || strlen($this->value) > 64 || preg_match('/^[A-Za-z0-9_-]+$/', $this->value) !== 1) {
            throw new InvalidArgumentException('the request id is not usable');
        }
    }
}
