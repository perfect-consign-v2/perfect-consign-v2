<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Http\Request;

/** One page of a server list, or the full filtered set for an export click. */
final class ItemPageWindow
{
    public const string PAGE = 'page';

    public const string ALL = 'all';

    /** @var list<int> */
    public const array LENGTHS = [10, 25, 50, 100];

    public function __construct(
        public readonly int $start,
        public readonly int $length,
        public readonly string $mode,
        public readonly int $draw,
    ) {
    }

    public static function from(Request $request): self
    {
        $draw = self::draw($request->query('draw'));
        if ($request->query('export') === '1') {
            return new self(0, 10, self::ALL, $draw);
        }

        return new self(self::start($request->query('start')), self::length($request->query('length')), self::PAGE, $draw);
    }

    private static function draw(string $value): int
    {
        if (preg_match('/^[0-9]{1,9}$/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }

    private static function start(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,9}$/', $value) !== 1) {
            throw new \InvalidArgumentException('That page is not available.');
        }

        return (int) $value;
    }

    private static function length(string $value): int
    {
        if ($value === '') {
            return 10;
        }
        if (preg_match('/^[0-9]+$/', $value) !== 1 || !in_array((int) $value, self::LENGTHS, true)) {
            throw new \InvalidArgumentException('That page size is not allowed.');
        }

        return (int) $value;
    }
}
