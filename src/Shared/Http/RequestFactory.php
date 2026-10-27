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
            self::clientAddress($server),
            RequestUploads::normalize($files),
            self::accept($server),
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
    private static function clientAddress(array $server): string
    {
        $address = $server['REMOTE_ADDR'] ?? '';
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
        $accept = $server['HTTP_ACCEPT'] ?? '';
        if (!is_string($accept)) {
            return '';
        }
        if (strlen($accept) > self::ACCEPT_MAX) {
            return substr($accept, 0, self::ACCEPT_MAX);
        }

        return $accept;
    }
}
