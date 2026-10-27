<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PerfectConsign\Bootstrap\RoleGate;

final class PageSurfaceCrawler
{
    public function __construct(
        private readonly FormClient $client,
        private readonly string $slug,
        private readonly string $role,
        private readonly int $maxVisits = 35,
        private readonly string $previewAs = '',
    ) {
    }

    /**
     * @param list<string> $startPaths
     * @return list<string>
     */
    public function crawlGets(array $startPaths): array
    {
        $queue = [];
        $seen = [];
        $failures = [];
        foreach ($startPaths as $start) {
            $queue[] = $start;
        }
        while ($queue !== [] && count($seen) < $this->maxVisits) {
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
            $page = $this->client->get($path);
            foreach ($this->pageFailures($path, $page) as $failure) {
                $failures[] = $failure;
            }
            foreach (HtmlPage::deadMallAnchors($page->body) as $dead) {
                $failures[] = $path . ' has dead anchor ' . $dead;
            }
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
     * Visit GET starts, then submit each safe form found on visited pages once.
     *
     * @param list<string> $startPaths
     * @return list<string>
     */
    public function crawlForms(array $startPaths): array
    {
        $failures = $this->crawlGets($startPaths);
        $queue = $startPaths;
        $seenPages = [];
        $submitted = [];
        while ($queue !== [] && count($seenPages) < $this->maxVisits) {
            $path = array_shift($queue);
            if (!is_string($path) || isset($seenPages[$path])) {
                continue;
            }
            $seenPages[$path] = true;
            if (!$this->roleAllows($path, 'GET')) {
                continue;
            }
            $page = $this->client->get($path);
            foreach (HtmlPage::mallSurfaceHrefs($page->body, $this->slug) as $href) {
                $href = $this->normalize($href);
                if ($href === null || isset($seenPages[$href])) {
                    continue;
                }
                $queue[] = $href;
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
                $posted = $this->client->post($form['action'], $form['fields']);
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
        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $pathOnly;
        }
        parse_str($query, $parts);
        $kept = [];
        foreach ($parts as $name => $value) {
            if (!is_string($name) || !is_string($value) || $value === '') {
                continue;
            }
            if ($name === 'q') {
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
