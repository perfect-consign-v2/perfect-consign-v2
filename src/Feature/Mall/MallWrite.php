<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class MallWrite
{
    /**
     * @param array{color: bool, category: bool, size: bool, condition: bool, department: bool, brand: bool} $flags
     */
    public static function posted(
        int $boothWidth,
        int $boothDepth,
        int $floorWidth,
        int $floorDepth,
        array $flags,
    ): self {
        return new self(
            $boothWidth,
            $boothDepth,
            $floorWidth,
            $floorDepth,
            new MallFlags(
                $flags['color'],
                $flags['category'],
                $flags['size'],
                $flags['condition'],
                $flags['department'],
                $flags['brand'],
            ),
        );
    }

    public function __construct(
        public readonly int $boothWidth,
        public readonly int $boothDepth,
        public readonly int $floorWidth,
        public readonly int $floorDepth,
        public readonly MallFlags $flags,
    ) {
    }
}
