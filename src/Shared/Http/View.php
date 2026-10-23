<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use PerfectConsign\Ledger\Money;
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
        return Money::format($minor);
    }

    public static function date(mixed $value): string
    {
        $text = self::text($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $text, $match) !== 1) {
            return $text;
        }

        return $match[2] . '-' . $match[3] . '-' . $match[1] . $match[4];
    }
}
