<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use RuntimeException;

final class FormClient
{
    private string $csrf = '';

    private string $cookieFile;

    private \CurlHandle $curl;

    public function __construct(private readonly string $baseUrl)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('the curl extension is required');
        }
        $cookieFile = tempnam(sys_get_temp_dir(), 'pc-cookie-');
        if ($cookieFile === false) {
            throw new RuntimeException('the cookie file could not be created');
        }
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('curl did not start');
        }
        $this->cookieFile = $cookieFile;
        $this->curl = $curl;
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    public function get(string $path): PageResponse
    {
        return $this->send('GET', $path, null, true, 60);
    }

    public function getWithoutRedirect(string $path): PageResponse
    {
        return $this->send('GET', $path, null, false, 60);
    }

    /**
     * @param array<string, string> $fields
     */
    public function post(string $path, array $fields, int $timeoutSeconds = 60): PageResponse
    {
        $fields['csrf'] = $this->csrf;
        $response = $this->send('POST', $path, $fields, false, $timeoutSeconds);
        if (!in_array($response->status, [301, 302, 303], true) || $response->location === '') {
            return $response;
        }

        return $this->send('GET', $this->locationPath($response->location), null, true, $timeoutSeconds);
    }

    public function signIn(string $slug, string $username, string $password): PageResponse
    {
        $this->get('/' . $slug . '/login');

        return $this->post('/' . $slug . '/login', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function ensurePeriod(string $slug): void
    {
        $book = $this->get('/' . $slug . '/book');
        if (HtmlPage::covers($book->body, ProbeMall::POSTING_DATE)) {
            return;
        }
        $opened = $this->post('/' . $slug . '/periods', [
            'starts_on' => ProbeMall::PERIOD_START,
            'ends_on' => ProbeMall::PERIOD_END,
        ]);
        if (!HtmlPage::covers($opened->body, ProbeMall::POSTING_DATE)) {
            throw new RuntimeException('the probe period was not opened. ' . HtmlPage::error($opened->body));
        }
    }

    /**
     * @param array<string, string>|null $fields
     */
    private function send(string $method, string $path, ?array $fields, bool $follow, int $timeoutSeconds): PageResponse
    {
        curl_reset($this->curl);
        $options = [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ];
        if ($fields !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($this->curl, $options);
        $raw = curl_exec($this->curl);
        if (!is_string($raw)) {
            throw new RuntimeException('the request failed: ' . curl_error($this->curl));
        }
        $status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        if (!is_int($status) || !is_int($headerSize)) {
            throw new RuntimeException('curl did not return a response');
        }
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $token = HtmlPage::csrf($body);
        if ($token !== null) {
            $this->csrf = $token;
        }

        return new PageResponse($status, $body, $this->location($headers));
    }

    private function locationPath(string $location): string
    {
        if (str_starts_with($location, $this->baseUrl)) {
            $location = substr($location, strlen($this->baseUrl));
        }
        if (str_starts_with($location, '/')) {
            return $location;
        }

        return '/' . $location;
    }

    private function location(string $headers): string
    {
        if (preg_match('/^Location:\s*(\S+)/mi', $headers, $match) !== 1) {
            return '';
        }

        return $match[1];
    }
}
