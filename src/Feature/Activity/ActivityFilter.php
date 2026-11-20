<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use DateTimeImmutable;
use PerfectConsign\Shared\Http\Request;

final class ActivityFilter
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $accountId,
        public readonly ?string $action,
        public readonly ?string $error,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $unknown = self::unknown($request);
        if ($unknown !== null) {
            return $unknown;
        }
        $span = self::span($request);
        if ($span === null) {
            return self::invalid('Enter a real date.');
        }
        $account = self::person($request->query('account'));
        if ($account === false) {
            return self::invalid('Choose a person.');
        }
        $action = self::verb($request->query('action'));
        if ($action === false) {
            return self::invalid('That action is not a filter.');
        }

        return self::chosen($span[0], $span[1], $account, $action);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function span(Request $request): ?array
    {
        $from = self::day($request->query('from'));
        $to = self::day($request->query('to'));
        if ($from === false || $to === false) {
            return null;
        }

        return [$from, self::ordered($from, $to)];
    }

    private static function chosen(string $from, string $to, string $account, string $action): self
    {
        return new self(
            $from === '' ? null : $from,
            $to === '' ? null : $to,
            $account === '' ? null : $account,
            $action === '' ? null : $action,
            null,
        );
    }

    private static function unknown(Request $request): ?self
    {
        foreach (array_keys($request->query) as $key) {
            if (!in_array($key, ['from', 'to', 'account', 'action'], true)) {
                return self::invalid('That filter is not on this page.');
            }
        }

        return null;
    }

    private static function ordered(string $from, string $to): string
    {
        if ($from !== '' && $to !== '' && $to < $from) {
            return $from;
        }

        return $to;
    }

    private static function person(string $account): string|false
    {
        if ($account !== '' && preg_match('/^[0-9a-fA-F-]{36}$/', $account) !== 1) {
            return false;
        }

        return $account;
    }

    private static function verb(string $action): string|false
    {
        if ($action !== '' && preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $action) !== 1) {
            return false;
        }

        return $action;
    }

    public function query(): string
    {
        $pairs = [];
        if ($this->from !== null) {
            $pairs['from'] = $this->from;
        }
        if ($this->to !== null) {
            $pairs['to'] = $this->to;
        }
        if ($this->accountId !== null) {
            $pairs['account'] = $this->accountId;
        }
        if ($this->action !== null) {
            $pairs['action'] = $this->action;
        }

        return http_build_query($pairs);
    }

    private static function invalid(string $error): self
    {
        return new self(null, null, null, null, $error);
    }

    private static function day(string $value): string|false
    {
        if ($value === '') {
            return '';
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            return false;
        }

        return $value;
    }
}
