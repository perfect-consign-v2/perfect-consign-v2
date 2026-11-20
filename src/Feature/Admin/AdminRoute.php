<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use Attribute;

/**
 * A platform route relative to AdminPath. The attribute cannot read the environment.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class AdminRoute
{
    /**
     * @param list<string> $methods
     */
    public function __construct(
        public readonly string $path,
        public readonly array $methods,
    ) {
    }
}
