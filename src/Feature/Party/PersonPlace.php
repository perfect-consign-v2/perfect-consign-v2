<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/** Street address fields for person create/save. */
final class PersonPlace
{
    public function __construct(
        public readonly ?string $street,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $postal = null,
    ) {
    }

    public static function empty(): self
    {
        return new self(null);
    }
}
