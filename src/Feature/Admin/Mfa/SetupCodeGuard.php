<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

final class SetupCodeGuard
{
    private const MAX_MARKUP = 262144;

    public static function blocked(string $markup, string $secret): bool
    {
        if ($markup === '' || strlen($markup) > self::MAX_MARKUP || self::leaks($markup, $secret)) {
            return true;
        }
        foreach (['script', 'style', 'iframe'] as $tag) {
            if (stripos($markup, '<' . $tag) !== false) {
                return true;
            }
        }

        return preg_match('/foreignobject|<!--|<!doctype|<\?| href| xlink| style| on/i', $markup) === 1;
    }

    private static function leaks(string $markup, string $secret): bool
    {
        return $secret !== '' && str_contains(strtolower($markup), strtolower($secret));
    }
}
