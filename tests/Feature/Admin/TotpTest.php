<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\Mfa\Base32;
use PerfectConsign\Feature\Admin\Mfa\RecoveryCode;
use PerfectConsign\Feature\Admin\Mfa\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890';

    public function testRfc6238Sha1SamplesUseTheSixDigitTruncation(): void
    {
        $samples = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
        ];
        foreach ($samples as $time => $code) {
            self::assertSame($code, Totp::codeForStep(self::RFC_SECRET, Totp::stepForTime($time)));
        }
    }

    public function testACodeTwoStepsAwayIsRefusedAndThePriorStepIsAccepted(): void
    {
        $now = 1234567890;
        $step = Totp::stepForTime($now);
        $current = Totp::codeForStep(self::RFC_SECRET, $step);
        $previous = Totp::codeForStep(self::RFC_SECRET, $step - 1);
        $distant = Totp::codeForStep(self::RFC_SECRET, $step + 2);

        self::assertSame($step, Totp::verify(self::RFC_SECRET, $current, $now));
        self::assertSame($step - 1, Totp::verify(self::RFC_SECRET, $previous, $now));
        self::assertNull(Totp::verify(self::RFC_SECRET, $distant, $now));
    }

    public function testAUsedStepCannotBeReplayed(): void
    {
        $now = 1111111111;
        $code = Totp::codeForStep(self::RFC_SECRET, Totp::stepForTime($now));
        $matched = Totp::verify(self::RFC_SECRET, $code, $now);
        self::assertNotNull($matched);
        self::assertNull(Totp::verify(self::RFC_SECRET, $code, $now, $matched));
    }

    public function testBase32RoundTripsTheAuthenticatorSecret(): void
    {
        $encoded = Base32::encode(self::RFC_SECRET);
        self::assertSame(self::RFC_SECRET, Base32::decode($encoded));
        self::assertSame($encoded, str_replace(' ', '', Base32::groups($encoded)));
        self::assertStringContainsString(' ', Base32::groups($encoded));
        self::assertNull(Base32::decode(''));
        self::assertNull(Base32::decode('!!!!'));
    }

    public function testRecoveryCodesNormalizeToAStableHash(): void
    {
        $codes = RecoveryCode::batch();
        self::assertCount(RecoveryCode::BATCH_SIZE, $codes);
        $seen = [];
        foreach ($codes as $code) {
            $normalized = RecoveryCode::normalize($code);
            self::assertSame(RecoveryCode::CODE_LENGTH, strlen($normalized));
            self::assertSame($normalized, RecoveryCode::normalize(strtolower($code) . ' '));
            $hash = RecoveryCode::hash($normalized);
            self::assertSame(64, strlen($hash));
            self::assertSame($hash, RecoveryCode::hash($normalized));
            $seen[$hash] = true;
        }
        self::assertCount(RecoveryCode::BATCH_SIZE, $seen);
        self::assertSame('', RecoveryCode::normalize('not a code'));
        self::assertSame('', RecoveryCode::hash(''));
    }
}
