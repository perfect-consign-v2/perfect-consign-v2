<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\LateFeeDue;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class LateFeeDueTest extends TestCase
{
    private const BOOTH = '01900000-0000-7000-8000-0000000000b1';

    /** @var list<array{0: string, 1: string, 2: int, 3: string, 4: int}> */
    private array $posted = [];

    public function testGraceHoldsTheFeeUntilTheDayAfterTheStart(): void
    {
        $policy = $this->policy(1, 2);
        $policy['grace'] = 1;
        $move = [$this->move('rent-charge:ab:2026-10', 1)];
        $this->apply('2026-10-01', $policy, $move);
        self::assertSame([], $this->posted);

        $this->apply('2026-10-02', $policy, $move);
        self::assertSame([[self::BOOTH, '2026-10', 1, '2026-10-02', 1]], $this->posted);
    }

    public function testAMonthKeyOnItsStartDatePostsTheFirstAmount(): void
    {
        $this->apply('2026-10-01', $this->policy(1, 2), [
            $this->move('rent-charge:ab:2026-10', 1),
        ]);

        self::assertSame([[self::BOOTH, '2026-10', 1, '2026-10-01', 1]], $this->posted);
    }

    public function testADayKeyPostsThatDay(): void
    {
        $this->apply('2026-10-16', $this->policy(10, 1), [
            $this->move('rent-charge:ab:2026-10-16', 100),
        ]);

        self::assertSame([[self::BOOTH, '2026-10-16', 1, '2026-10-16', 10]], $this->posted);
    }

    public function testALeadingColonStillReadsTheMonth(): void
    {
        $this->apply('2026-10-01', $this->policy(10, 20), [
            $this->move('rent-charge::2026-10', 100),
        ]);

        self::assertSame([[self::BOOTH, '2026-10', 1, '2026-10-01', 10]], $this->posted);
    }

    public function testASecondFeeInThePeriodUsesTheNextAmount(): void
    {
        $policy = $this->policy(10, 25);
        $policy['grace'] = 10;
        $this->apply('2026-10-21', $policy, [
            $this->move('rent-charge:ab:2026-10', 100),
        ]);

        self::assertSame([
            [self::BOOTH, '2026-10', 1, '2026-10-11', 10],
            [self::BOOTH, '2026-10', 2, '2026-10-21', 25],
        ], $this->posted);
    }

    public function testALaterPeriodStartsAgainAtTheFirstAmount(): void
    {
        $policy = $this->policy(10, 25);
        $policy['grace'] = 20;
        $this->apply('2026-11-21', $policy, [
            $this->move('rent-charge:ab:2026-10', 100),
            $this->move('rent-charge:ab:2026-11', 100),
        ]);

        self::assertSame([
            [self::BOOTH, '2026-10', 1, '2026-10-21', 10],
            [self::BOOTH, '2026-11', 1, '2026-11-21', 10],
        ], $this->posted);
    }

    public function testAPostedFirstFeeStillAllowsTheNextOne(): void
    {
        $policy = $this->policy(10, 25);
        $policy['grace'] = 10;
        $this->apply(
            '2026-10-21',
            $policy,
            [$this->move('rent-charge:ab:2026-10', 100)],
            ['late-fee:' . self::BOOTH . ':2026-10:1'],
        );

        self::assertSame([[self::BOOTH, '2026-10', 2, '2026-10-21', 25]], $this->posted);
    }

    public function testFeesStayOffUntilThePolicyAndTheBalanceQualify(): void
    {
        $move = [$this->move('rent-charge:ab:2026-10', 100)];
        $this->apply('nope', $this->policy(10, 20), $move);
        $this->apply('2026-10-01', $this->policy(10, 20), $move, rentals: false);
        $this->apply('2026-10-01', $this->policy(10, 20, enabled: false), $move);
        $this->apply('2026-10-01', $this->policy(0, 20), $move);
        $this->apply('2026-10-01', $this->policy(10, 20), [
            $this->move('rent-charge:ab:2026-10', 0),
        ]);
        $this->apply('2026-09-30', $this->policy(10, 20), $move);
        $this->apply('2026-10-01', $this->policy(10, 20), $move, ['late-fee:' . self::BOOTH . ':2026-10:1']);

        self::assertSame([], $this->posted);
    }

    public function record(string $booth, string $period, int $ordinal, string $date, int $amount): void
    {
        $this->posted[] = [$booth, $period, $ordinal, $date, $amount];
    }

    public function testAPaidPeriodDoesNotTakeAnotherFee(): void
    {
        $policy = $this->policy(10, 20);
        $policy['grace'] = 10;
        $this->apply('2026-10-21', $policy, [
            $this->move('rent-charge:ab:2026-10', 0),
        ]);

        self::assertSame([], $this->posted);
    }

    /**
     * @param array{enabled: bool, grace: int, first: int, next: int} $policy
     * @param list<array<string, mixed>> $moves
     * @param list<string> $keys
     */
    private function apply(string $today, array $policy, array $moves, array $keys = [], bool $rentals = true): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturnCallback(static function (string $sql) use ($moves, $keys): array {
            if (str_contains($sql, "source_type = 'late_fee'")) {
                return array_map(static fn (string $key): array => ['posting_key' => $key], $keys);
            }

            return $moves;
        });
        $mall = $this->createStub(Statements::class);
        $mall->method('rows')->willReturn([[
            'operating_mode' => 'both',
            'payout_cadence' => 'monthly',
            'rent_prepaid_from_prior_sales' => 'f',
            'booth_rentals_enabled' => $rentals ? 't' : 'f',
            'rent_cycle_day' => 1,
            'late_fees_enabled' => $policy['enabled'] ? 't' : 'f',
            'late_fee_grace_days' => $policy['grace'],
            'late_fee_first_minor' => $policy['first'],
            'late_fee_next_minor' => $policy['next'],
            'late_fee_effective_on' => '2000-01-01',
        ]]);
        $fees = $this->createStub(Statements::class);
        $fees->method('bound')->willReturn([]);
        $test = $this;
        $ledger = new class ($test) extends LedgerStore {
            public function __construct(private readonly LateFeeDueTest $test)
            {
            }

            public function roleAccount(string $role): string
            {
                return 'account';
            }

            public function post(JournalDraft $draft): string
            {
                $booth = $draft->lines[0]['subledger_ref'] ?? '';
                $rest = substr($draft->postingKey, strlen('late-fee:' . $booth . ':'));
                $cut = strrpos($rest, ':');
                $period = $cut === false ? $rest : substr($rest, 0, $cut);
                $ordinal = $cut === false ? 1 : (int) substr($rest, $cut + 1);
                $this->test->record($booth, $period, $ordinal, $draft->postingDate, $draft->lines[0]['debit']);

                return 'journal';
            }
        };
        (new LateFeeDue($statements, new MallPayoutSettings($mall), new LateFee($ledger, $fees)))->apply($today);
    }

    /**
     * @return array{enabled: bool, grace: int, first: int, next: int}
     */
    private function policy(int $first, int $next, bool $enabled = true): array
    {
        return ['enabled' => $enabled, 'grace' => 0, 'first' => $first, 'next' => $next];
    }

    public function testAPeriodEndsTheDayBeforeTheNextMonthAndSlotsStopThere(): void
    {
        $found = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName() . '(',
            (new \ReflectionClass(LateFeeDue::class))->getMethods()
        );
        self::assertContains('periodEnd(', $found);
        self::assertContains('slots(', $found);
    }

    /**
     * @return array<string, mixed>
     */
    private function move(string $key, int $debit): array
    {
        return [
            'posting_key' => $key,
            'source_type' => 'rent_charge',
            'booth_id' => self::BOOTH,
            'debit_minor' => $debit,
            'credit_minor' => 0,
        ];
    }
}
