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

    public function testDeployDoesNotRequireAPasswordChangeWhenOwnerAlreadyMatches(): void
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'deploy-demo.sh';
        $script = file_get_contents($path);
        self::assertIsString($script);
        self::assertStringContainsString('set-owner.php', $script);
        self::assertStringContainsString('idempotent', $script);
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
    }
}
