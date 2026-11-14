<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use RuntimeException;

/**
 * Binds template variables by name and refuses a key that is not an identifier.
 */
final class TemplateScope
{
    /**
     * @param array<mixed, mixed> $data
     */
    public static function render(string $path, array $data): void
    {
        /** @var array<string, mixed> $bound */
        $bound = [];
        foreach ($data as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || str_starts_with($name, '__')) {
                throw new RuntimeException('invalid template variable');
            }
            $bound[$name] = $value;
        }
        /**
         * @param array<string, mixed> $__data
         */
        $render = static function (string $__templatePath, array $__data): void {
            $__name = '';
            $__value = null;
            foreach ($__data as $__name => $__value) {
                ${$__name} = $__value;
            }
            unset($__data, $__name, $__value);
            require $__templatePath;
        };
        $render($path, $bound);
    }
}
