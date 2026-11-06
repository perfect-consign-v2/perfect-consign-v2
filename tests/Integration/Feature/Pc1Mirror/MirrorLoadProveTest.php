<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\MasterLoader;
use PerfectConsign\Feature\Pc1Mirror\MirrorArtifact;
use PerfectConsign\Feature\Pc1Mirror\MirrorConstants;
use PerfectConsign\Feature\Pc1Mirror\MirrorMall;
use PerfectConsign\Feature\Pc1Mirror\MirrorProver;
use PerfectConsign\Feature\Pc1Mirror\MirrorTarget;
use PerfectConsign\Feature\Pc1Mirror\MirrorWiper;
use PerfectConsign\Feature\Pc1Mirror\OpeningLoader;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class MirrorLoadProveTest extends TestCase
{
    public function testFixtureLoadAndProveMatchToTheCent(): void
    {
        $root = dirname(__DIR__, 4);
        ProbeMall::ensure($root);
        $fixture = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures'
            . DIRECTORY_SEPARATOR . 'pc1-mirror';
        $artifact = MirrorArtifact::open($fixture);
        $target = MirrorTarget::open('local');
        $pdo = $target->connection->pdo();
        (new MirrorWiper())->wipe($pdo, MirrorConstants::TARGET_SLUG);
        $mall = MirrorMall::provision($pdo, $root . DIRECTORY_SEPARATOR . 'sql');
        $reopened = MirrorMall::openExisting($pdo);
        self::assertSame($mall->schema, $reopened->schema);
        $idMap = (new MasterLoader())->load($mall, $artifact);
        (new OpeningLoader())->load($mall, $artifact, $idMap, '2026-11-05');
        $failures = (new MirrorProver())->prove($mall, $artifact);
        self::assertSame([], $failures);
    }

    public function testProveFailsWhenExtractBalancesDrift(): void
    {
        $root = dirname(__DIR__, 4);
        ProbeMall::ensure($root);
        $fixture = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures'
            . DIRECTORY_SEPARATOR . 'pc1-mirror';
        $scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc1-mirror-' . bin2hex(random_bytes(4));
        $this->copyTree($fixture, $scratch);
        $artifact = MirrorArtifact::open($scratch);
        $target = MirrorTarget::open('local');
        $pdo = $target->connection->pdo();
        (new MirrorWiper())->wipe($pdo, MirrorConstants::TARGET_SLUG);
        $mall = MirrorMall::provision($pdo, $root . DIRECTORY_SEPARATOR . 'sql');
        $idMap = (new MasterLoader())->load($mall, $artifact);
        (new OpeningLoader())->load($mall, $artifact, $idMap, '2026-11-05');
        self::assertSame([], (new MirrorProver())->prove($mall, $artifact));

        $balances = $artifact->readJson('balances.json');
        $balances['vendor_payable'][0]['cents'] = 5001;
        $artifact->writeJson('balances.json', $balances);
        $failures = (new MirrorProver())->prove($mall, $artifact);
        self::assertNotSame([], $failures);
        $this->removeTree($scratch);
    }

    private function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0700, true) && !is_dir($to)) {
            self::fail('could not create scratch artifact');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $to . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0700) && !is_dir($target)) {
                    self::fail('could not copy fixture dir');
                }
                continue;
            }
            if (!copy($item->getPathname(), $target)) {
                self::fail('could not copy fixture file');
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
