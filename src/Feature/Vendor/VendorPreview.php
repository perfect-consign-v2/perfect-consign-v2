<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;

final class VendorPreview
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly PartyStore $parties,
    ) {
    }

    public function id(string $slug): string
    {
        $this->app->open($slug, true);
        $own = $this->actor->vendor();
        if ($own !== '') {
            return $own;
        }
        if ($this->actor->role() !== 'owner') {
            $this->app->notFound();
        }
        $as = self::token($this->app->request()->query('as'));
        if ($as === '' || $this->parties->findVendor($as) === null) {
            $this->app->notFound();
        }

        return $as;
    }

    public function url(string $path): string
    {
        return self::with($path, self::token($this->app->request()->query('as')));
    }

    public static function token(string $value): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            return '';
        }

        return strtolower($value);
    }

    public static function onPortal(string $path, string $slug): bool
    {
        $prefix = '/' . $slug . '/vendor';

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    public static function with(string $path, string $as): string
    {
        if ($as === '') {
            return $path;
        }
        $join = str_contains($path, '?') ? '&' : '?';

        return $path . $join . 'as=' . rawurlencode($as);
    }
}
