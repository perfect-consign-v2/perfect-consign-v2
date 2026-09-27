<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use PerfectConsign\Shared\Scalar\RowValue;
use RuntimeException;

final class View
{
    public function __construct(private readonly string $templateDirectory)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): void
    {
        $templatePath = $this->templateDirectory . DIRECTORY_SEPARATOR . $template . '.php';
        if (!is_file($templatePath)) {
            throw new RuntimeException('missing template ' . $template);
        }
        $data['templateDirectory'] = $this->templateDirectory;
        extract($data, EXTR_SKIP);
        require $templatePath;
    }

    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    public static function minor(mixed $value): int
    {
        return RowValue::integer($value);
    }

    public static function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
