<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use chillerlan\QRCode\QRCode;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The otpauth URI and the SVG a phone scans on first platform-admin sign-in.
 */
final class SetupCode
{
    private const ISSUER = 'PC2';

    private const FAILURE = 'the authenticator image could not be drawn';

    private const INCOMPLETE = 'the authenticator setup is incomplete';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function uri(string $email, string $secret): string
    {
        if (!$this->emailIsUsable($email) || !$this->secretIsUsable($secret)) {
            throw new InvalidArgumentException(self::INCOMPLETE);
        }
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => self::ISSUER,
            'algorithm' => 'SHA1',
            'digits' => Totp::DIGITS,
            'period' => Totp::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . rawurlencode(self::ISSUER) . ':' . rawurlencode($email) . '?' . $query;
    }

    public function svg(string $uri): string
    {
        $secret = $this->secretFrom($uri);
        try {
            $drawn = (new QRCode(self::options()))->render($uri);
        } catch (Throwable $error) {
            $this->failed($error);
        }
        if (!is_string($drawn) || $drawn === '') {
            $this->failed(new RuntimeException(self::FAILURE));
        }

        try {
            return $this->accept($drawn, $secret);
        } catch (RuntimeException $error) {
            $this->failed($error);
        }
    }

    public function accept(string $markup, string $secret): string
    {
        return (new SetupCodeMarkup())->image($markup, $secret);
    }

    /**
     * @return array<string, bool|int|string>
     */
    private static function options(): array
    {
        return [
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'addQuietzone' => true,
            'drawLightModules' => false,
            'drawCircularModules' => false,
            'svgUseFillAttributes' => true,
            'svgDefs' => '',
            'cssClass' => '',
            'svgPreserveAspectRatio' => 'xMidYMid',
            'eol' => "\n",
        ];
    }

    private function secretFrom(string $uri): string
    {
        if (!str_starts_with($uri, 'otpauth://totp/')) {
            throw new InvalidArgumentException(self::INCOMPLETE);
        }
        $query = parse_url($uri, PHP_URL_QUERY);
        if (!is_string($query)) {
            throw new InvalidArgumentException(self::INCOMPLETE);
        }
        $fields = [];
        parse_str($query, $fields);
        $secret = $fields['secret'] ?? null;
        if (!is_string($secret) || !$this->secretIsUsable($secret)) {
            throw new InvalidArgumentException(self::INCOMPLETE);
        }

        return $secret;
    }

    private function emailIsUsable(string $email): bool
    {
        return strlen($email) <= 200 && preg_match('/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/', $email) === 1;
    }

    private function secretIsUsable(string $secret): bool
    {
        return preg_match('/\A[A-Z2-7]+\z/', $secret) === 1 && Base32::decode($secret) !== null;
    }

    private function failed(Throwable $error): never
    {
        $this->logger->error('platform admin authenticator image failed', [
            'exception' => $error::class,
        ]);

        throw new RuntimeException(self::FAILURE);
    }
}
