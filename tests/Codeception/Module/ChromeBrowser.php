<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Module;

use Codeception\Module;
use Codeception\TestInterface;
use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Support\ChromeProbe;
use PerfectConsign\Tests\Support\ChromeSession;
use PerfectConsign\Tests\Support\ProbeMall;
use RuntimeException;

/**
 * Codeception acceptance driver over chrome-php (no Chromedriver).
 * Same stack as PHPUnit's browser suite / surface workflow.
 */
final class ChromeBrowser extends Module
{
    private ?ChromeProbe $probe = null;

    private ?ChromeSession $session = null;

    public function _before(TestInterface $test): void
    {
        $this->probe = new ChromeProbe();
        $this->session = $this->probe->open(ProbeHarness::require()->baseUrl());
    }

    public function _after(TestInterface $test): void
    {
        $this->session?->close();
        $this->session = null;
        $this->probe?->close();
        $this->probe = null;
    }

    public function amOnPage(string $page): void
    {
        $this->session()->open($page);
    }

    public function see(string $text, mixed $selector = null): void
    {
        $html = $this->html();
        if ($selector !== null) {
            throw new RuntimeException('ChromeBrowser::see does not support CSS selectors yet');
        }
        if (!str_contains($html, $text)) {
            $this->fail('Failed asserting that page contains "' . $text . '"');
        }
    }

    public function dontSee(string $text, mixed $selector = null): void
    {
        $html = $this->html();
        if ($selector !== null) {
            throw new RuntimeException('ChromeBrowser::dontSee does not support CSS selectors yet');
        }
        if (str_contains($html, $text)) {
            $this->fail('Failed asserting that page does not contain "' . $text . '"');
        }
    }

    public function seeInCurrentUrl(string $uri): void
    {
        $path = $this->session()->currentPath();
        if (!str_contains($path, $uri)) {
            $this->fail('Failed asserting that current URL "' . $path . '" contains "' . $uri . '"');
        }
    }

    public function click(string $css): void
    {
        $this->session()->clickSelector($css, true);
    }

    public function clickWithoutNavigation(string $css): void
    {
        $this->session()->clickSelector($css, false);
    }

    /**
     * @param array<string, string> $fields
     */
    public function submitPageForm(string $action, array $fields): void
    {
        $this->session()->submit($action, $fields);
    }

    public function submitStickerJob(string $action, string $itemId): void
    {
        $this->session()->submitStickerJob($action, $itemId);
    }

    public function signInAsOwner(): void
    {
        $this->session()->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
    }

    public function signInAs(string $username, string $password): void
    {
        $this->session()->signIn(ProbeMall::SLUG, $username, $password);
    }

    public function grabPageSource(): string
    {
        return $this->html();
    }

    public function grabCurrentPath(): string
    {
        return $this->session()->currentPath();
    }

    public function grabFromCurrentUrl(string $regex = '~.*/(.*)~'): mixed
    {
        $path = $this->session()->currentPath();
        if (preg_match($regex, $path, $matches) !== 1) {
            $this->fail('current URL "' . $path . '" did not match ' . $regex);
        }

        return $matches[1] ?? $matches[0];
    }

    private function session(): ChromeSession
    {
        if (!$this->session instanceof ChromeSession) {
            throw new RuntimeException('Chrome session was not started');
        }

        return $this->session;
    }

    private function html(): string
    {
        return $this->session()->html();
    }
}
