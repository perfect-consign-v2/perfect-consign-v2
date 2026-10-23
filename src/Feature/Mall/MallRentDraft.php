<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\Money;

final class MallRentDraft
{
    public ?bool $rentals = null;

    public ?int $cycleDay = null;

    public ?bool $prepaid = null;

    public ?bool $lateFees = null;

    public ?int $lateGrace = null;

    public ?int $lateFirst = null;

    public ?int $lateNext = null;

    public function __construct(
        private readonly MallApp $app,
        private readonly MallPayoutSettings $payouts,
    ) {
    }

    public function money(MallSettings $mall): MallMoneyForm
    {
        return new MallMoneyForm($this->app, $mall, $this->payouts, $this);
    }

    public function read(): ?string
    {
        $mode = $this->app->request()->field('operating_mode');
        $enabled = $mode === ''
            ? $this->app->request()->field('booth_rentals_enabled') === '1'
            : MallPayoutSettings::capabilities($mode)['rentals'];
        $this->rentals = $enabled;
        $day = $this->payouts->cycleDay();
        if ($enabled && !$this->payouts->cycleFrozen()) {
            $raw = $this->app->request()->field('rent_cycle_day');
            if ($raw === '') {
                $day = $this->payouts->cycleDay();
            } elseif (preg_match('/^(?:[1-9]|1[0-9]|2[0-8])$/', $raw) !== 1) {
                return 'the rent cycle day is a whole number from 1 to 28';
            } else {
                $day = (int) $raw;
            }
        }
        $this->cycleDay = $day;
        $this->prepaid = $enabled
            ? $this->app->request()->field('rent_prepaid_from_prior_sales') === '1'
            : $this->payouts->rentPrepaid();

        return $this->readLateFees();
    }

    public function save(): void
    {
        if ($this->lateFees === null || $this->lateGrace === null) {
            return;
        }
        if ($this->lateFirst === null || $this->lateNext === null) {
            return;
        }
        $this->payouts->saveLateFees(
            $this->lateFees,
            $this->lateGrace,
            $this->lateFirst,
            $this->lateNext,
        );
    }

    public function dollars(int $minor): string
    {
        if ($minor < 1) {
            return '';
        }

        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function readLateFees(): ?string
    {
        if ($this->rentals !== true) {
            return null;
        }
        if ($this->app->request()->field('late_fee_grace_days') === ''
            && $this->app->request()->field('late_fees_enabled') !== '1'
            && $this->app->request()->field('late_fee_first') === ''
            && $this->app->request()->field('late_fee_next') === ''
        ) {
            return null;
        }
        $enabled = $this->app->request()->field('late_fees_enabled') === '1';
        $this->lateFees = $enabled;
        if ($this->graceDays($enabled) !== null) {
            return 'late fee grace is a whole number from 0 to 28';
        }

        return $this->feeAmounts($enabled);
    }

    private function graceDays(bool $enabled): ?string
    {
        $graceRaw = $this->app->request()->field('late_fee_grace_days');
        if ($graceRaw === '' && !$enabled) {
            $graceRaw = '0';
        }
        if (preg_match('/^(?:0|[1-9]|1[0-9]|2[0-8])$/', $graceRaw) !== 1) {
            return 'late fee grace is a whole number from 0 to 28';
        }
        $this->lateGrace = (int) $graceRaw;

        return null;
    }

    private function feeAmounts(bool $enabled): ?string
    {
        $first = $this->optionalMinor('late_fee_first');
        $next = $this->optionalMinor('late_fee_next');
        if ($first === false || $next === false) {
            return 'enter the late fee in dollars and cents';
        }
        if ($enabled && ($first < 1 || $next < 1)) {
            return 'enter the late fee in dollars and cents';
        }
        $this->lateFirst = $first;
        $this->lateNext = $next;

        return null;
    }

    private function optionalMinor(string $field): int|false
    {
        $raw = trim($this->app->request()->field($field));
        if ($raw === '') {
            return 0;
        }
        try {
            return Money::parseMinor($raw);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
