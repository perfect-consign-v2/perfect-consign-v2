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
        public readonly ?string $before,
        public readonly ?string $after,
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
        $account = self::token($request->query('account'));
        if ($account === false) {
            return self::invalid('Choose a person.');
        }
        $action = self::verb($request->query('action'));
        if ($action === false) {
            return self::invalid('That action is not a filter.');
        }
        $cursor = self::cursors($request);
        if (is_string($cursor)) {
            return self::invalid($cursor);
        }

        return self::chosen($span[0], $span[1], $account, $action, $cursor[0], $cursor[1]);
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

    private static function chosen(
        string $from,
        string $to,
        string $account,
        string $action,
        ?string $before,
        ?string $after,
    ): self {
        return new self(
            $from === '' ? null : $from,
            $to === '' ? null : $to,
            $account === '' ? null : $account,
            $action === '' ? null : $action,
            $before,
            $after,
            null,
        );
    }

    private static function unknown(Request $request): ?self
    {
        foreach (array_keys($request->query) as $key) {
            if (!in_array($key, ['from', 'to', 'account', 'action', 'before', 'after'], true)) {
                return self::invalid('That filter is not on this page.');
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}|string
     */
    private static function cursors(Request $request): array|string
    {
        $before = self::token($request->query('before'));
        $after = self::token($request->query('after'));
        if ($before === false || $after === false) {
            return 'That page is not in this list.';
        }
        if ($before !== '' && $after !== '') {
            return 'That filter is not on this page.';
        }

        return [
            $before === '' ? null : $before,
            $after === '' ? null : $after,
        ];
    }

    private static function ordered(string $from, string $to): string
    {
        if ($from !== '' && $to !== '' && $to < $from) {
            return $from;
        }

        return $to;
    }

    private static function token(string $value): string|false
    {
        if ($value !== '' && preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            return false;
        }

        return $value;
    }

    private static function verb(string $action): string|false
    {
        if ($action !== '' && !ActivityPhrase::has($action)) {
            return false;
        }

        return $action;
    }

    public function query(): string
    {
        return http_build_query($this->pairs());
    }

    public function pageQuery(string $cursor, string $id): string
    {
        $pairs = $this->pairs();
        if ($cursor === 'before' || $cursor === 'after') {
            $pairs[$cursor] = $id;
        }

        return http_build_query($pairs);
    }

    /**
     * @return array<string, string>
     */
    private function pairs(): array
    {
        $pairs = [];
        foreach ([
            'from' => $this->from,
            'to' => $this->to,
            'account' => $this->accountId,
            'action' => $this->action,
        ] as $key => $value) {
            if ($value !== null) {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }

    private static function invalid(string $error): self
    {
        return new self(null, null, null, null, null, null, $error);
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
