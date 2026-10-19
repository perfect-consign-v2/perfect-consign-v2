<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Register;

use InvalidArgumentException;
use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class DenominationCountTest extends TestCase
{
    public function testEveryBillCountAndAPositiveDropAreRequired(): void
    {
        $counts = [];
        foreach (DenominationCount::KEYS as $key) {
            $counts['count_' . $key] = '0';
        }
        $counts['count_1'] = '';
        try {
            DenominationCount::fromRequest(new Request('POST', '/registers', [], $counts));
            self::fail('a blank coin count was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('every bill and coin count is required', $error->getMessage());
        }
        try {
            DenominationCount::money('nope', true);
            self::fail('a word was accepted as cash');
        } catch (InvalidArgumentException $error) {
            self::assertSame('enter an amount in dollars and cents', $error->getMessage());
        }
        try {
            DenominationCount::money('0.00', false);
            self::fail('a zero drop was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the amount must be greater than zero', $error->getMessage());
        }
        self::assertSame(0, DenominationCount::counted(''));
        self::assertSame(125, DenominationCount::counted('1.25'));
        $zeros = [];
        foreach (DenominationCount::KEYS as $key) {
            $zeros['count_' . $key] = '0';
        }
        self::assertSame(0, DenominationCount::fromRequest(new Request('POST', '/registers', [], $zeros))[1]);
        self::assertSame(0, DenominationCount::money('0', true));
        self::assertSame(1, DenominationCount::money('0.01', false));
        self::assertSame(100, DenominationCount::money('1.00', false));
        self::assertSame('$0.00', DenominationCount::dollars(0));
        self::assertSame('$2.50', DenominationCount::dollars(250));
        self::assertSame('-$0.50', DenominationCount::dollars(-50));
    }
}
