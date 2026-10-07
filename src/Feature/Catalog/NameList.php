<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

final class NameList
{
    /**
     * @param non-empty-string $key
     * @param non-empty-string $table
     * @param non-empty-string $itemColumn
     * @param non-empty-string $flag
     * @param non-empty-string $path
     * @param non-empty-string $singular
     * @param non-empty-string $plural
     */
    public function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly string $itemColumn,
        public readonly string $flag,
        public readonly string $path,
        public readonly string $singular,
        public readonly string $plural,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self('color', 'colors', 'color_id', 'use_color', 'colors', 'color', 'Colors'),
            new self('category', 'categories', 'category_id', 'use_category', 'categories', 'category', 'Categories'),
            new self('size', 'sizes', 'size_id', 'use_size', 'sizes', 'size', 'Sizes'),
            new self('condition', 'conditions', 'condition_id', 'use_condition', 'conditions', 'condition', 'Conditions'),
            new self('department', 'departments', 'department_id', 'use_department', 'departments', 'department', 'Departments'),
        ];
    }

    public static function fromPath(string $path): ?self
    {
        foreach (self::all() as $list) {
            if ($list->path === $path) {
                return $list;
            }
        }

        return null;
    }
}
