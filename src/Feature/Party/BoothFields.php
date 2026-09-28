<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\MallApp;

final class BoothFields
{
    public function __construct(private readonly MallApp $app)
    {
    }

    /**
     * @return array{width: ?int, depth: ?int}
     */
    public function size(): array
    {
        $width = $this->feet($this->app->request()->field('width_ft'));
        $depth = $this->feet($this->app->request()->field('depth_ft'));
        if ($width === false || $depth === false) {
            throw new \InvalidArgumentException('booth size is whole feet');
        }
        if (($width === null) !== ($depth === null)) {
            throw new \InvalidArgumentException('set both booth dimensions or leave both empty');
        }

        return ['width' => $width, 'depth' => $depth];
    }

    private function feet(string $value): int|false|null
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
            return false;
        }

        return (int) $value;
    }
}
