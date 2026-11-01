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

    /**
     * Click every safe in-page control (nav/btn links + data-print). Skips money/destructive hrefs.
     *
     * @return list<string> failure messages
     */
    public function clickEverySafeControlOnPage(string $returnPath): array
    {
        $inventory = $this->evaluateJson(<<<'JS'
(() => {
  const nodes = Array.from(document.querySelectorAll(
    'a.nav-item, a.menu-item, a.seg-btn, [data-print]'
  )).filter((el) => !el.closest('table, tbody, tr[id]'));
  return nodes.map((el, i) => {
    el.setAttribute('data-cest-click', String(i));
    return {
      id: String(i),
      href: el.getAttribute('href') || '',
      print: el.hasAttribute('data-print'),
      text: (el.innerText || el.getAttribute('aria-label') || '').trim().slice(0, 48),
    };
  });
})()
JS);

        $failures = [];
        foreach ($inventory as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_string($item['id'])) {
                continue;
            }
            $href = isset($item['href']) && is_string($item['href']) ? $item['href'] : '';
            $print = !empty($item['print']);
            if (!$print && (self::dangerousHref($href) || self::entityRowHref($href))) {
                continue;
            }
            $this->session()->open($returnPath);
            $this->stampClickTargets();
            try {
                if ($print) {
                    $this->session()->clickSelector('[data-cest-click="' . $item['id'] . '"]', false);
                } else {
                    $this->session()->clickSelector('[data-cest-click="' . $item['id'] . '"]', true);
                }
            } catch (\Throwable $retry) {
                $failures[] = 'click #' . $item['id'] . ' (' . ($item['text'] ?? '') . '): ' . $retry->getMessage();
                continue;
            }
            $html = $this->html();
            if (str_contains($html, 'This address is not a page in')) {
                $failures[] = 'click #' . $item['id'] . ' (' . ($item['text'] ?? '') . ') → miss page';
            }
            if (str_contains($html, 'Fatal error') || str_contains($html, 'Uncaught')) {
                $failures[] = 'click #' . $item['id'] . ' (' . ($item['text'] ?? '') . ') → fatal';
            }
        }

        return $failures;
    }

    private function stampClickTargets(): void
    {
        $this->evaluateJson(<<<'JS'
(() => {
  Array.from(document.querySelectorAll(
    'a.nav-item, a.menu-item, a.seg-btn, [data-print]'
  )).filter((el) => !el.closest('table, tbody, tr[id]')).forEach((el, i) => el.setAttribute('data-cest-click', String(i)));
  return [];
})()
JS);
    }

    private static function entityRowHref(string $href): bool
    {
        $path = explode('?', $href, 2)[0];

        return preg_match(
            '#/(?:inventory|vendors|booths|journals|registers|staff)/[0-9a-fA-F-]{36}#',
            $path
        ) === 1;
    }

    private static function dangerousHref(string $href): bool
    {
        $path = explode('?', $href, 2)[0];
        foreach ([
            '/logout',
            '/delete',
            '/pay',
            '/payout',
            '/void',
            '/password',
            '/backup',
            '/charge',
            '/reset-test-data',
            '/close',
            '/undo',
            '/remove',
            '/dispose',
            '/end',
            '/export',
            '/letters',
            '/registers',
        ] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return $href === '' || $href === '#';
    }

    /**
     * @return list<mixed>
     */
    private function evaluateJson(string $expression): array
    {
        $value = $this->session()->evaluate($expression);
        if (!is_array($value)) {
            return [];
        }

        return $value;
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
