<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\MirrorException;
use PerfectConsign\Feature\Pc1Mirror\MirrorTarget;
use PHPUnit\Framework\TestCase;

final class MirrorTargetTest extends TestCase
{
    public function testUnknownTargetIsRefused(): void
    {
        $this->expectException(MirrorException::class);
        MirrorTarget::open('prod');
    }

    public function testV2RefusesApiPerfectconsignHost(): void
    {
        $this->withEnv([
            'STALL_MIRROR_V2_DB_HOST' => 'api.perfectconsign.com',
            'STALL_MIRROR_V2_DB_PORT' => '5432',
            'STALL_MIRROR_V2_DB_NAME' => 'consign',
            'STALL_MIRROR_V2_DB_USER' => 'stall',
            'STALL_MIRROR_V2_DB_PASSWORD' => 'secret',
        ], function (): void {
            $this->expectException(MirrorException::class);
            $this->expectExceptionMessage('production');
            MirrorTarget::open('v2');
        });
    }

    public function testV2RequiresEnv(): void
    {
        $this->withEnv([
            'STALL_MIRROR_V2_DB_HOST' => false,
            'STALL_MIRROR_V2_DB_PORT' => false,
            'STALL_MIRROR_V2_DB_NAME' => false,
            'STALL_MIRROR_V2_DB_USER' => false,
            'STALL_MIRROR_V2_DB_PASSWORD' => false,
            'STALL_DB_HOST' => false,
            'STALL_DB_PORT' => false,
            'STALL_DB_NAME' => false,
            'STALL_DB_USER' => false,
            'STALL_DB_PASSWORD' => false,
        ], function (): void {
            $this->expectException(MirrorException::class);
            $this->expectExceptionMessage('STALL_MIRROR_V2_DB_*');
            MirrorTarget::open('v2');
        });
    }

    public function testV2AllowsConfiguredHost(): void
    {
        $this->withEnv([
            'STALL_MIRROR_V2_DB_HOST' => 'mirror-lab.example',
            'STALL_MIRROR_V2_DB_PORT' => '5432',
            'STALL_MIRROR_V2_DB_NAME' => 'consign',
            'STALL_MIRROR_V2_DB_USER' => 'stall',
            'STALL_MIRROR_V2_DB_PASSWORD' => 'secret',
            'STALL_MIRROR_V2_ALLOW_HOST' => 'other-host.example',
        ], function (): void {
            $this->expectException(MirrorException::class);
            $this->expectExceptionMessage('v2 mirror host must be');
            MirrorTarget::open('v2');
        });
    }

    /**
     * @param array<string, string|false> $values
     * @param callable(): void $run
     */
    private function withEnv(array $values, callable $run): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        try {
            $run();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }
}
