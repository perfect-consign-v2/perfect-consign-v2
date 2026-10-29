<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PHPUnit\Framework\TestCase;

final class PasswordRulesTest extends TestCase
{
    public function testAShortPasswordIsRefused(): void
    {
        $choice = new PasswordChoice();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Use a password of at least 8 characters.');
        $choice->hash('short');
    }

    public function testAPasswordOfEightCharactersIsHashed(): void
    {
        $hashed = (new PasswordChoice())->hash('eight-ok');

        self::assertTrue(password_verify('eight-ok', $hashed));
    }

    public function testTheCurrentPasswordMustMatch(): void
    {
        $choice = new PasswordChoice();
        $hashed = $choice->hash('current-1');
        $choice->current('current-1', $hashed);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That password is wrong.');
        $choice->current('current-2', $hashed);
    }

    public function testAChangedPasswordMustDifferAndMatchItsConfirmation(): void
    {
        $choice = new PasswordChoice();
        $hashed = $choice->hash('current-1');

        try {
            $choice->changed('current-1', $hashed, 'next-one', 'next-two');
            self::fail('mismatched passwords were accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Those new passwords do not match.', $error->getMessage());
        }
        try {
            $choice->changed('current-1', $hashed, 'current-1', 'current-1');
            self::fail('the current password was reused');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a different password.', $error->getMessage());
        }

        $next = $choice->changed('current-1', $hashed, 'next-one', 'next-one');
        self::assertTrue(password_verify('next-one', $next));
    }

    public function testAReplacementPasswordMustDifferAndMatchItsConfirmation(): void
    {
        $choice = new PasswordChoice();
        $hashed = $choice->hash('current-1');

        try {
            $choice->replacement($hashed, 'next-one', 'next-two');
            self::fail('mismatched passwords were accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Those new passwords do not match.', $error->getMessage());
        }
        try {
            $choice->replacement($hashed, 'current-1', 'current-1');
            self::fail('the current password was reused');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a different password.', $error->getMessage());
        }

        $next = $choice->replacement($hashed, 'next-one', 'next-one');
        self::assertTrue(password_verify('next-one', $next));
    }

    public function testARecoveryEmailIsTrimmedAndRefusesABlankOrOverlongAddress(): void
    {
        $addresses = new RecoveryAddress();

        self::assertSame('a@b.c', $addresses->parse('  a@b.c  '));
        self::assertSame($this->address(200), $addresses->parse($this->address(200)));
        foreach (['', '   ', 'not-an-email', 'a@b', $this->address(201)] as $email) {
            try {
                $addresses->parse($email);
                self::fail('accepted ' . $email);
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Enter an email.', $error->getMessage());
            }
        }
    }

    private function address(int $length): string
    {
        $tail = '@' . str_repeat('b', $length - 67) . '.c';

        return str_repeat('a', 64) . $tail;
    }
}
