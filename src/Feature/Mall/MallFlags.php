<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class MallFlags
{
    public function __construct(
        public readonly bool $color,
        public readonly bool $category,
        public readonly bool $size,
        public readonly bool $condition,
        public readonly bool $department,
        public readonly bool $brand,
    ) {
    }

    /**
     * @return list<string>
     */
    public function stored(): array
    {
        return [
            $this->color ? 'true' : 'false',
            $this->category ? 'true' : 'false',
            $this->size ? 'true' : 'false',
            $this->condition ? 'true' : 'false',
            $this->department ? 'true' : 'false',
            $this->brand ? 'true' : 'false',
        ];
    }
}
