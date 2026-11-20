<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\Mfa\Base32;
use PerfectConsign\Feature\Admin\Mfa\SetupCode;
use PerfectConsign\Feature\Admin\Mfa\Totp;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

final class SetupCodeTest extends TestCase
{
    public function testTheUriNamesPc2AndKeepsTheColonBetweenIssuerAndEmail(): void
    {
        $secret = Base32::encode('12345678901234567890');
        $email = 'ops+admin@example.test';
        $uri = $this->code()->uri($email, $secret);

        self::assertSame(
            'otpauth://totp/PC2:ops%2Badmin%40example.test?secret=' . $secret
            . '&issuer=PC2&algorithm=SHA1&digits=' . (string) Totp::DIGITS
            . '&period=' . (string) Totp::PERIOD_SECONDS,
            $uri,
        );
    }

    public function testABrokenEmailIsRefused(): void
    {
        $secret = Base32::encode('12345678901234567890');
        $code = $this->code();
        $this->expectException(\InvalidArgumentException::class);
        $code->uri('not an email', $secret);
    }

    public function testASecretThatIsNotBase32IsRefused(): void
    {
        $code = $this->code();
        $this->expectException(\InvalidArgumentException::class);
        $code->uri('ops+admin@example.test', 'not a key');
    }

    public function testTheImageIsOneSvgAndDoesNotRepeatTheSecret(): void
    {
        $secret = Base32::encode('12345678901234567890');
        $email = str_repeat('a', 64) . '@' . str_repeat('b', 130) . '.test';
        $log = new SetupCodeLog();
        $svg = (new SetupCode($log))->svg((new SetupCode($log))->uri($email, $secret));

        self::assertSame(1, preg_match_all('/<svg\b/', $svg));
        self::assertStringContainsString('<path fill="#000" d="', $svg);
        self::assertStringNotContainsString($secret, $svg);
        self::assertStringNotContainsString('otpauth://', $svg);
        self::assertStringNotContainsString('<style', strtolower($svg));
        self::assertStringNotContainsString('<script', strtolower($svg));
        self::assertSame('', $log->line);
        self::assertLessThanOrEqual(200, strlen($email));
    }

    public function testAStyleOrScriptPayloadIsRefused(): void
    {
        $secret = Base32::encode('12345678901234567890');
        $code = $this->code();
        $path = '<path fill="#000" d="M0 0 h1 v1 h-1Z"/>';
        $open = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1" preserveAspectRatio="xMidYMid">';
        foreach (['<style>path{fill:#000}</style>', '<script></script>'] as $payload) {
            try {
                $code->accept($open . $payload . $path . '</svg>', $secret);
                self::fail('a ' . $payload . ' image was shown');
            } catch (RuntimeException $error) {
                self::assertSame('the authenticator image could not be drawn', $error->getMessage());
                self::assertStringNotContainsString($secret, $error->getMessage());
            }
        }
    }

    private function code(): SetupCode
    {
        return new SetupCode($this->logger());
    }

    private function logger(): LoggerInterface
    {
        return new SetupCodeLog();
    }
}

final class SetupCodeLog extends AbstractLogger
{
    public string $line = '';

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $encoded = json_encode($context);
        $this->line .= (string) $message . (is_string($encoded) ? $encoded : '');
    }
}
