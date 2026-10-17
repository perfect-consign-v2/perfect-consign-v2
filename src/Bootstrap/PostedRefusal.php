<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Ledger\DatabaseRefusal;
use Psr\Log\LoggerInterface;

final class PostedRefusal
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function phrase(\Throwable $error): string
    {
        $detail = $error->getPrevious();
        $this->logger->info('ledger refused a post', [
            'message' => $error->getMessage(),
            'database' => $detail instanceof \Throwable ? $detail->getMessage() : null,
        ]);

        return DatabaseRefusal::phrase($error->getMessage());
    }
}
