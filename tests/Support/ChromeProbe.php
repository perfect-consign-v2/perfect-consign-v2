<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;
use RuntimeException;

final class ChromeProbe
{
    private ProcessAwareBrowser $browser;

    public function __construct()
    {
        $home = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc2-chrome-home-' . bin2hex(random_bytes(4));
        if (!mkdir($home, 0700, true) && !is_dir($home)) {
            throw new RuntimeException('chrome home directory could not be created: ' . $home);
        }

        $this->browser = self::factory()->createBrowser([
            'headless' => true,
            'windowSize' => [1200, 900],
            // GitHub Actions / container runners refuse a sandboxed Chrome and have a tiny /dev/shm.
            'noSandbox' => true,
            'envVariables' => [
                'HOME' => $home,
                'XDG_CONFIG_HOME' => $home . DIRECTORY_SEPARATOR . 'config',
                'XDG_CACHE_HOME' => $home . DIRECTORY_SEPARATOR . 'cache',
            ],
            'customFlags' => [
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-crash-reporter',
                '--no-crashpad',
            ],
            'startupTimeout' => 60,
        ]);
    }

    public function open(string $baseUrl): ChromeSession
    {
        return new ChromeSession($this->browser->createPage(), $baseUrl);
    }

    public function close(): void
    {
        $this->browser->close();
    }

    private static function factory(): BrowserFactory
    {
        foreach (self::binaries() as $path) {
            if (is_file($path)) {
                return new BrowserFactory($path);
            }
        }

        return new BrowserFactory();
    }

    /**
     * @return list<string>
     */
    private static function binaries(): array
    {
        $configured = getenv('CHROME_PATH');
        $paths = [];
        if (is_string($configured) && $configured !== '') {
            $paths[] = $configured;
        }
        $paths[] = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
        $paths[] = 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe';
        $paths[] = 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe';
        $paths[] = '/usr/bin/google-chrome';
        $paths[] = '/usr/bin/google-chrome-stable';
        $paths[] = '/usr/bin/chromium';
        $paths[] = '/usr/bin/chromium-browser';

        return $paths;
    }
}
