<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectConsign\Shared\Database\PgBool;

final class MallFlags
{
    public function __construct(
        public readonly bool $color,
        public readonly bool $category,
        public readonly bool $size,
        public readonly bool $condition,
        public readonly bool $department,
        public readonly bool $brand,
        public readonly bool $portal,
        public readonly bool $inventory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function stored(): array
    {
        return [
            PgBool::literal($this->color),
            PgBool::literal($this->category),
            PgBool::literal($this->size),
            PgBool::literal($this->condition),
            PgBool::literal($this->department),
            PgBool::literal($this->brand),
            PgBool::literal($this->portal),
            PgBool::literal($this->inventory),
        ];
    }
}
