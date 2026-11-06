<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\MirrorException;
use PerfectConsign\Feature\Pc1Mirror\MirrorWiper;
use PHPUnit\Framework\TestCase;

final class MirrorWiperTest extends TestCase
{
    public function testOnlyBeccasMirrorMayBeWiped(): void
    {
        $this->expectException(MirrorException::class);
        (new MirrorWiper())->wipe(new \PDO('sqlite::memory:'), 'mall');
    }
}
