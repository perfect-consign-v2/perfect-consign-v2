<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

/**
 * The browser's device cookie. It is a random value; the database keeps only
 * its SHA-256, once for each account that signed in from this browser. Over
 * HTTPS it is a __Host- cookie.
 */
final class SignInDevice
{
    private const SECURE_NAME = '__Host-pc_device';

    private const PLAIN_NAME = 'pc_device';

    private ?string $token;

    /**
     * @param array<mixed> $cookies
     */
    public function __construct(array $cookies, private readonly bool $https)
    {
        $value = $cookies[$this->name()] ?? null;
        $this->token = is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1 ? $value : null;
    }

    public function name(): string
    {
        return $this->https ? self::SECURE_NAME : self::PLAIN_NAME;
    }

    public function hash(): ?string
    {
        return $this->token === null ? null : hash('sha256', $this->token);
    }

    /**
     * Makes a token when the browser has none and returns its hash.
     */
    public function claim(): string
    {
        if ($this->token === null) {
            $this->token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        return hash('sha256', $this->token);
    }

    public function send(int $days): void
    {
        if ($this->token === null || headers_sent()) {
            return;
        }
        setcookie($this->name(), $this->token, [
            'expires' => time() + $days * 86400,
            'path' => '/',
            'secure' => $this->https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
