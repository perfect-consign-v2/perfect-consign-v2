<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Deploy;

use PHPUnit\Framework\TestCase;

final class DeployDemoScriptTest extends TestCase
{
    public function testDeployRebuildsTheFeatureControllerManifest(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        self::assertFileExists($path);
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('composer', $script);
        self::assertStringContainsString('install', $script);
        self::assertStringContainsString('/opt/plesk/php/8.5/bin/php', $script);
        self::assertStringContainsString('# pc2-php-8.5', $script);
        self::assertStringContainsString('export PATH="%s:${PATH:-}"', $script);
        $markerAt = strpos($script, '# pc2-php-8.5');
        $loginPathAt = strpos($script, 'export PATH="$(dirname -- "$php_bin"):${PATH:-}"');
        self::assertNotFalse($markerAt);
        self::assertNotFalse($loginPathAt);
        self::assertGreaterThan($markerAt, $loginPathAt);
        self::assertMatchesRegularExpression(
            '/export PATH=.*dirname -- "\$php_bin"/',
            $script
        );
        self::assertMatchesRegularExpression(
            '/composer["\s].*build:routes|build:routes/',
            $script
        );
        $pathAt = strpos($script, 'export PATH=');
        $installAt = strpos($script, 'install --no-dev');
        $routesAt = strpos($script, 'build:routes');
        self::assertNotFalse($pathAt);
        self::assertNotFalse($installAt);
        self::assertNotFalse($routesAt);
        self::assertGreaterThan($pathAt, $installAt);
        self::assertGreaterThan($installAt, $routesAt);

        $composerJson = file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'composer.json');
        self::assertIsString($composerJson);
        self::assertMatchesRegularExpression(
            '/"build:routes"\s*:\s*"@php bin\/build-feature-routes\.php"/',
            $composerJson
        );
    }

    public function testSeedDemoComposesDeployThenSeedMall(): void
    {
        $root = dirname(__DIR__, 3);
        $scriptPath = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'seed-demo.sh';
        self::assertFileExists($scriptPath);
        $script = file_get_contents($scriptPath);
        self::assertIsString($script);
        self::assertStringContainsString('bin/deploy-demo.sh', $script);
        self::assertStringContainsString('DEPLOY_SKIP_MIGRATE=1', $script);
        self::assertStringContainsString('bin/seed-mall.php', $script);
        self::assertStringContainsString('reinstall-empty-database.php', $script);
        self::assertStringContainsString('STALL_ALLOW_PLATFORM_REINSTALL', $script);
        self::assertStringContainsString('set-owner.php', $script);
        self::assertStringContainsString('/opt/plesk/php/8.5/bin/php', $script);
        $skipAt = strpos($script, 'DEPLOY_SKIP_MIGRATE=1');
        $reinstallAt = strpos($script, 'reinstall-empty-database.php');
        $seedAt = strpos($script, 'bin/seed-mall.php');
        self::assertNotFalse($skipAt);
        self::assertNotFalse($reinstallAt);
        self::assertNotFalse($seedAt);
        self::assertGreaterThan($skipAt, $reinstallAt);
        self::assertGreaterThan($reinstallAt, $seedAt);

        $yamlPath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'seed-demo.yml';
        self::assertFileExists($yamlPath);
        $yaml = file_get_contents($yamlPath);
        self::assertIsString($yaml);
        self::assertStringContainsString('workflow_dispatch', $yaml);
        self::assertStringContainsString('bash bin/seed-demo.sh', $yaml);
    }

    public function testDeployRecoversMigrationFileChangedWhenReinstallAllowed(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('DEPLOY_SKIP_MIGRATE', $script);
        self::assertStringContainsString('changed after it was applied', $script);
        self::assertStringContainsString('reinstall-empty-database.php', $script);
        self::assertStringContainsString('provision-platform.php', $script);
        self::assertStringContainsString('STALL_ALLOW_PLATFORM_REINSTALL', $script);
        $migrateAt = strpos($script, 'bin/migrate.php" platform');
        $reinstallAt = strpos($script, 'reinstall-empty-database.php');
        self::assertNotFalse($migrateAt);
        self::assertNotFalse($reinstallAt);
        self::assertGreaterThan($migrateAt, $reinstallAt);
    }

    public function testDeployDoesNotRequireAPasswordChangeWhenOwnerAlreadyMatches(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('set-owner.php', $script);
        self::assertStringContainsString('idempotent', $script);
    }

    public function testDeployPlantsPlatformAdminWhenEnvPasswordIsSet(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('set-platform-admin.php', $script);
        self::assertStringContainsString('STALL_PLATFORM_ADMIN_PASSWORD', $script);
        $ownerAt = strpos($script, 'set-owner.php');
        $adminAt = strpos($script, 'set-platform-admin.php');
        self::assertNotFalse($ownerAt);
        self::assertNotFalse($adminAt);
        self::assertGreaterThan($ownerAt, $adminAt);
    }


    public function testDeployProbesTheEmailSignInRootNotSlugLogin(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('https://v2.perfectconsign.com/', $script);
        self::assertStringContainsString("live=https://v2.perfectconsign.com/", $script);
        self::assertDoesNotMatchRegularExpression(
            '#curl[^\\n]*v2\\.perfectconsign\\.com/mall/login#',
            $script
        );
        self::assertMatchesRegularExpression(
            '#curl[^\\n]*"\$live"#',
            $script
        );
        self::assertStringContainsString('Sign-in is only at `/`', $script);
        self::assertStringContainsString('STALL_TRUSTED_PROXIES', $script);
        self::assertStringContainsString('Secure cookie', $script);
        self::assertMatchesRegularExpression('/\\[Ss\\]et-\\[Cc\\]ookie/', $script);
    }

    public function testDeployReexecsAfterResetSoTipScriptRuns(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        $resetAt = strpos($script, 'git reset --hard "$sha"');
        $cleanAt = strpos($script, 'git clean -fd -- sql/');
        $reexecAt = strpos($script, 'exec bash "${root}/bin/deploy-demo.sh"');
        self::assertNotFalse($resetAt);
        self::assertNotFalse($cleanAt);
        self::assertNotFalse($reexecAt);
        self::assertGreaterThan($resetAt, $cleanAt);
        self::assertGreaterThan($cleanAt, $reexecAt);
        self::assertStringContainsString('DEPLOY_REEXEC', $script);
        self::assertStringContainsString('pre-reset inode', $script);
        self::assertStringContainsString('Only scrub untracked SQL', $script);
        self::assertStringContainsString('composer.phar', $script);
        self::assertStringContainsString('getcomposer.org/installer', $script);
        self::assertStringContainsString('composer.github.io/installer.sig', $script);
        self::assertStringContainsString('hash_file(\'sha384\'', $script);
        self::assertStringContainsString('composer installer sha384 mismatch', $script);
        self::assertStringContainsString('installing composer.phar', $script);
        self::assertStringNotContainsString('git clean -fdx', $script);
        self::assertDoesNotMatchRegularExpression('/^git clean -fd$/m', $script);
    }

    public function testDeployAppliesPlatformAfterBeforeTenantMigrate(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        $platformAt = strpos($script, 'bin/migrate.php" platform');
        $afterAt = strpos($script, 'bin/migrate.php" platform-after');
        $tenantAt = strpos($script, 'bin/migrate.php" tenant mall');
        self::assertNotFalse($platformAt);
        self::assertNotFalse($afterAt);
        self::assertNotFalse($tenantAt);
        self::assertGreaterThan($platformAt, $afterAt);
        self::assertGreaterThan($afterAt, $tenantAt);
        self::assertStringNotContainsString('forget-tenant-migrations', $script);
        self::assertStringContainsString('two-installer baseline', $script);
        self::assertStringContainsString('Stamped sql/0*.sql files are immutable', $script);
        self::assertStringContainsString('is not provisioned', $script);
    }
}
