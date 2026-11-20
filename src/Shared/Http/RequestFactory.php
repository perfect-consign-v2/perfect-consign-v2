<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class RequestFactory
{
    private const ACCEPT_MAX = 1024;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public static function from(
        array $server,
        array $query,
        array $post,
        array $files,
        string $trustedProxiesEnv,
    ): Request {
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        if (!is_string($method) || $method === '') {
            $method = 'GET';
        }
        $https = TlsSignal::secure($server, $trustedProxiesEnv);

        return new Request(
            strtoupper($method),
            RequestPath::fromServer($server),
            $query,
            $post,
            SameHostReferer::path($server, $https),
            RequestOrigin::resolve($server, $https),
            self::clientAddress($server, $trustedProxiesEnv),
            RequestUploads::normalize($files),
            new RequestTrace(
                self::accept($server),
                self::target($server),
                self::host($server),
                self::userAgent($server),
                self::rawReferer($server),
            ),
        );
    }

    public static function fromGlobals(): Request
    {
        $trusted = getenv('STALL_TRUSTED_PROXIES');

        return self::from(
            self::stringKeyed($_SERVER),
            self::stringKeyed($_GET),
            self::stringKeyed($_POST),
            self::stringKeyed($_FILES),
            is_string($trusted) ? $trusted : '',
        );
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $source): array
    {
        $out = [];
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function clientAddress(array $server, string $trustedProxiesEnv): string
    {
        $remote = self::validAddress($server['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !TlsSignal::peerIsTrusted($remote, $trustedProxiesEnv)) {
            return $remote;
        }
        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || trim($forwarded) === '') {
            return $remote;
        }
        $hops = array_reverse(explode(',', $forwarded));
        foreach ($hops as $hop) {
            $candidate = self::validAddress($hop);
            if ($candidate === '') {
                continue;
            }
            if (!TlsSignal::peerIsTrusted($candidate, $trustedProxiesEnv)) {
                return $candidate;
            }
        }

        return $remote;
    }

    private static function validAddress(mixed $address): string
    {
        if (!is_string($address)) {
            return '';
        }
        $address = trim($address);
        if (preg_match('/^[A-Za-z0-9:.]{1,64}$/', $address) !== 1) {
            return '';
        }

        return $address;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function accept(array $server): string
    {
        return self::plain($server['HTTP_ACCEPT'] ?? '', self::ACCEPT_MAX);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function target(array $server): string
    {
        $uri = $server['REQUEST_URI'] ?? '/';
        if (!is_string($uri) || $uri === '') {
            return '/';
        }
        $cut = strpos($uri, '?');
        $path = $cut === false ? $uri : substr($uri, 0, $cut);
        $query = $cut === false ? '' : substr($uri, $cut + 1);
        $keys = [];
        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                $name = explode('=', $pair, 2)[0];
                $decoded = rawurldecode(str_replace('+', ' ', $name));
                $clean = self::plain($decoded, 64);
                if ($clean !== '') {
                    $keys[] = $clean;
                }
            }
        }
        $target = $path;
        if ($keys !== []) {
            $target .= '?' . implode('&', $keys);
        }

        return self::plain($target, 500);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function host(array $server): string
    {
        return self::plain($server['HTTP_HOST'] ?? '', 200);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function userAgent(array $server): string
    {
        return self::plain($server['HTTP_USER_AGENT'] ?? '', 200);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function rawReferer(array $server): string
    {
        return self::plain($server['HTTP_REFERER'] ?? '', 500);
    }

    private static function plain(mixed $value, int $max): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        if (!is_string($clean) || $clean === '') {
            return '';
        }
        if (strlen($clean) > $max) {
            return substr($clean, 0, $max);
        }

        return $clean;
    }
}
