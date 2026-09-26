<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;

final class ChromeProbe
{
    private ProcessAwareBrowser $browser;

    public function __construct()
    {
        $this->browser = self::factory()->createBrowser([
            'headless' => true,
            'windowSize' => [1200, 900],
            'startupTimeout' => 20,
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

        return $paths;
    }
}
