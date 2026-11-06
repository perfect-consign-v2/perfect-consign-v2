<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\MirrorException;
use PerfectConsign\Feature\Pc1Mirror\Pc1ReadOnlyConnection;
use PHPUnit\Framework\TestCase;

final class Pc1ReadOnlyConnectionTest extends TestCase
{
    public function testV2HostIsRefusedForExtract(): void
    {
        $this->expectException(MirrorException::class);
        new Pc1ReadOnlyConnection('v2.perfectconsign.com', '5432', 'consign', 'reader', 'secret');
    }

    public function testMissingPasswordIsRefused(): void
    {
        $previous = getenv('PROD_DB_PASS');
        $previousPc = getenv('PC_DB_PASSWORD');
        putenv('PROD_DB_PASS');
        putenv('PC_DB_PASSWORD');
        putenv('PROD_DB_PASSWORD');
        putenv('PC_DB_PASS');
        try {
            $this->expectException(MirrorException::class);
            Pc1ReadOnlyConnection::fromEnv();
        } finally {
            if (is_string($previous)) {
                putenv('PROD_DB_PASS=' . $previous);
            }
            if (is_string($previousPc)) {
                putenv('PC_DB_PASSWORD=' . $previousPc);
            }
        }
    }
}
