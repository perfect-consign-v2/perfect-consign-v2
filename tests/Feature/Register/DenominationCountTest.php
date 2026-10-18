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
    }
}
