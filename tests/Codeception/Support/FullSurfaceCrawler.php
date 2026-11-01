<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Bootstrap\RoleGate;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\PageResponse;
use PerfectConsign\Tests\Support\SurfaceFormDenyList;

/**
 * Unbounded mall crawl: every same-mall href (not nav-only) and every safe form.
 */
final class FullSurfaceCrawler
{
    /** Safety rail so row-link explosion (parties/inventory) cannot hang the suite. */
    private const MAX_PAGES = 120;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly FormClient $client,
        private readonly string $slug,
        private readonly string $role,
        private readonly string $previewAs = '',
    ) {
    }

    /**
     * @param list<string> $startPaths
     * @return list<string>
     */
    public function crawlGets(array $startPaths): array
    {
        $queue = $startPaths;
        $seen = [];
        $failures = [];
        while ($queue !== []) {
            if (count($seen) >= self::MAX_PAGES) {
                // Intentional bound — row links on parties/inventory are not the surface bar.
                break;
            }
            $path = array_shift($queue);
            if (!is_string($path) || $path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            if (!$this->roleAllows($path, 'GET')) {
                continue;
            }
            if ($this->previewAs !== '' && $this->vendorPortalPath($path) && !$this->hasAs($path)) {
                $failures[] = $path . ' (vendor portal link missing as=)';
                continue;
            }
            try {
                $page = $this->client->get($path, self::REQUEST_TIMEOUT_SECONDS);
            } catch (\RuntimeException $error) {
                $failures[] = $path . ' GET failed: ' . $error->getMessage();
                continue;
            }
            foreach ($this->pageFailures($path, $page) as $failure) {
                $failures[] = $failure;
            }
            foreach (HtmlPage::deadMallAnchors($page->body) as $dead) {
                $failures[] = $path . ' has dead anchor ' . $dead;
            }
            // Nav / segment / button links — not every parties or inventory row.
            foreach (HtmlPage::mallSurfaceHrefs($page->body, $this->slug) as $href) {
                $href = $this->normalize($href);
                if ($href === null || isset($seen[$href])) {
                    continue;
                }
                $queue[] = $href;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $startPaths
     * @return list<string>
     */
    public function crawlForms(array $startPaths): array
    {
        // Hub pages only — link following is crawlGets. Re-POST after redirect churn is denied.
        $failures = [];
        $submitted = [];
        foreach ($startPaths as $path) {
            if (!is_string($path) || $path === '' || !$this->roleAllows($path, 'GET')) {
                continue;
            }
            try {
                $page = $this->client->get($path, self::REQUEST_TIMEOUT_SECONDS);
            } catch (\RuntimeException $error) {
                $failures[] = $path . ' GET failed: ' . $error->getMessage();
                continue;
            }
            foreach (HtmlPage::mallForms($page->body, $this->slug) as $form) {
                $key = $form['action'] . "\0" . json_encode($form['fields'], JSON_THROW_ON_ERROR);
                if (isset($submitted[$key])) {
                    continue;
                }
                $submitted[$key] = true;
                if (SurfaceFormDenyList::blocks($form['action'], $this->slug)) {
                    continue;
                }
                if (!$this->roleAllows($form['action'], 'POST')) {
                    continue;
                }
                if ($form['requiredEmpty']) {
                    continue;
                }
                try {
                    $posted = $this->client->post(
                        $form['action'],
                        $form['fields'],
                        self::REQUEST_TIMEOUT_SECONDS
                    );
                } catch (\RuntimeException $error) {
                    $failures[] = $form['action'] . ' from ' . $path . ' POST failed: ' . $error->getMessage();
                    continue;
                }
                foreach ($this->pageFailures($form['action'] . ' from ' . $path, $posted) as $failure) {
                    $failures[] = $failure;
                }
            }
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function pageFailures(string $from, PageResponse $page): array
    {
        $failures = [];
        if ($page->status >= 500) {
            $failures[] = $from . ' returned ' . (string) $page->status;
        }
        if (HtmlPage::isMissPage($page->body)) {
            $failures[] = $from . ' rendered not found';
        }

        return $failures;
    }

    private function roleAllows(string $path, string $method): bool
    {
        $pathOnly = explode('?', $path, 2)[0];
        $prefix = '/' . $this->slug;
        if ($pathOnly === $prefix) {
            $rest = '';
        } elseif (str_starts_with($pathOnly, $prefix . '/')) {
            $rest = substr($pathOnly, strlen($prefix));
        } else {
            return false;
        }

        return RoleGate::allows($this->role, $rest === '' ? '/' : $rest, $method);
    }

    private function vendorPortalPath(string $path): bool
    {
        $pathOnly = explode('?', $path, 2)[0];
        $prefix = '/' . $this->slug . '/vendor';

        return $pathOnly === $prefix || str_starts_with($pathOnly, $prefix . '/');
    }

    private function hasAs(string $path): bool
    {
        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }
        parse_str($query, $parts);
        $as = $parts['as'] ?? null;

        return is_string($as) && strcasecmp($as, $this->previewAs) === 0;
    }

    private function normalize(string $path): ?string
    {
        $pathOnly = explode('?', $path, 2)[0];
        if (str_ends_with($pathOnly, '.csv') || str_ends_with($pathOnly, '.zip')) {
            return null;
        }
        if (str_ends_with($pathOnly, '/backup') || str_ends_with($pathOnly, '/photo')) {
            return null;
        }
        // Row Edit/Open buttons — entity GETs come from seededStarts, not every list row.
        if (preg_match(
            '#^/' . preg_quote($this->slug, '#')
            . '/(?:inventory|vendors|booths|journals|registers|staff|parties)/[0-9a-fA-F-]{36}#',
            $pathOnly
        ) === 1) {
            return null;
        }
        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $pathOnly;
        }
        parse_str($query, $parts);
        $kept = [];
        // Drop one-shot / volatile keys or the crawl never finishes (confirm=uuid forever).
        $drop = [
            'q' => true,
            'confirm' => true,
            'rename' => true,
            'close' => true,
            'notice' => true,
            'flash' => true,
            'error' => true,
        ];
        foreach ($parts as $name => $value) {
            if (!is_string($name) || !is_string($value) || $value === '') {
                continue;
            }
            if (isset($drop[$name])) {
                continue;
            }
            $kept[$name] = $value;
        }
        ksort($kept);
        if ($kept === []) {
            return $pathOnly;
        }

        return $pathOnly . '?' . http_build_query($kept);
    }
}
