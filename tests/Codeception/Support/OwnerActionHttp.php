<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\PageResponse;

/**
 * Records every owner request and the sentence the screen returned.
 */
final class OwnerActionHttp
{
    /** @var list<string> */
    public array $failures = [];

    /** @var list<array{method: string, path: string}> */
    public array $hits = [];

    public function __construct(
        private readonly FormClient $client,
        private readonly string $slug,
    ) {
    }

    public function client(): FormClient
    {
        return $this->client;
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $needles
     */
    public function get(string $suffix, array $needles = [], bool $allowMiss = false, int $timeout = 90): PageResponse
    {
        return $this->fetch('GET', $this->url($suffix), null, $needles, $timeout, $allowMiss);
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $needles
     */
    public function post(string $suffix, array $fields, array $needles = [], int $timeout = 90, bool $allowMiss = false): PageResponse
    {
        return $this->fetch('POST', $this->url($suffix), $fields, $needles, $timeout, $allowMiss);
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $needles
     */
    public function json(string $suffix, array $fields, array $needles = []): PageResponse
    {
        $path = $this->url($suffix);
        $this->remember('POST', $path);
        $page = $this->ask(fn (): PageResponse => $this->client->postJson($path, $fields), 'POST ' . $path);
        $this->rememberLanded($path, $page);
        if ($page->status === 0 && $page->body === '') {
            return $page;
        }

        return $this->check($page, 'POST ' . $suffix, $needles, false);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $files
     * @param list<string> $needles
     */
    public function upload(string $suffix, array $fields, array $files, array $needles = []): PageResponse
    {
        $path = $this->url($suffix);
        $this->remember('POST', $path);
        $page = $this->ask(fn (): PageResponse => $this->client->upload($path, $fields, $files), 'POST ' . $path);
        $this->rememberLanded($path, $page);
        if ($page->status === 0 && $page->body === '') {
            return $page;
        }

        return $this->check($page, 'POST ' . $suffix, $needles, false);
    }

    /**
     * @param array<string, string>|null $fields
     * @param list<string> $needles
     */
    public function fetch(
        string $method,
        string $path,
        ?array $fields,
        array $needles,
        int $timeout,
        bool $allowMiss,
    ): PageResponse {
        $this->remember($method, $path);
        $page = $this->ask(
            fn (): PageResponse => $method === 'GET'
                ? $this->client->get($path, $timeout)
                : $this->client->post($path, $fields ?? [], $timeout),
            $method . ' ' . $path,
        );
        $this->rememberLanded($path, $page);
        if ($page->status === 0 && $page->body === '') {
            return $page;
        }

        return $this->check($page, $method . ' ' . $path, $needles, $allowMiss);
    }

    public function url(string $suffix): string
    {
        return '/' . $this->slug . $suffix;
    }

    public function id(string $html, string $pattern, string $label): string
    {
        if (preg_match($pattern, $html, $match) !== 1) {
            $this->failures[] = $label . ' was not on the page. ' . $this->snippet($html);

            return '';
        }

        return strtolower($match[1]);
    }

    /**
     * @param callable(): PageResponse $request
     */
    private function ask(callable $request, string $label): PageResponse
    {
        try {
            return $request();
        } catch (\RuntimeException $error) {
            $this->failures[] = $label . ' ' . $error->getMessage();

            return new PageResponse(0, '', '', []);
        }
    }

    private function rememberLanded(string $requestedPath, PageResponse $page): void
    {
        $landed = parse_url($page->effectiveUrl, PHP_URL_PATH);
        if (!is_string($landed) || $landed === '' || $landed === $requestedPath) {
            return;
        }
        $this->hits[] = ['method' => 'GET', 'path' => $landed];
    }

    private function remember(string $method, string $path): void
    {
        $this->hits[] = [
            'method' => strtoupper($method),
            'path' => explode('?', $path, 2)[0],
        ];
    }

    /**
     * @param list<string> $needles
     */
    private function check(PageResponse $page, string $label, array $needles, bool $allowMiss): PageResponse
    {
        if ($page->status >= 500 || $page->status === 0) {
            $this->failures[] = $label . ' returned HTTP ' . $page->status . '. ' . $this->snippet($page->body);

            return $page;
        }
        if (str_contains($page->body, 'Fatal error') || str_contains($page->body, 'Uncaught')) {
            $this->failures[] = $label . ' crashed. ' . $this->snippet($page->body);

            return $page;
        }
        $miss = str_contains($page->body, 'This address is not a page in');
        if ($miss && !$allowMiss && !$this->named($page->body, $needles)) {
            $landed = (string) parse_url($page->effectiveUrl, PHP_URL_PATH);
            $this->failures[] = $label . ' is not a page.'
                . ($landed !== '' && !str_ends_with($label, ' ' . $landed) ? ' It ended at ' . $landed . '.' : '');

            return $page;
        }
        if ($needles !== [] && !$this->named($page->body, $needles)) {
            $error = HtmlPage::error($page->body);
            $this->failures[] = $label . ' did not say ' . implode(' / ', $needles)
                . '. ' . ($error !== '' ? $error : $this->snippet($page->body));
        }

        return $page;
    }

    /**
     * @param list<string> $needles
     */
    private function named(string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function snippet(string $body): string
    {
        if (preg_match('/data-message="([^"]*)"/', $body, $match) === 1) {
            $message = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
            if ($message !== '') {
                return $message;
            }
        }
        $error = HtmlPage::error($body);
        if ($error !== '') {
            return $error;
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return substr($text, 0, 240);
    }
}
