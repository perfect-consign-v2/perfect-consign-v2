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

    /**
     * Public asset URL with a filemtime query so browsers pick up a new file
     * after deploy when AssetServer uses a long Cache-Control max-age.
     */
    public static function asset(string $path): string
    {
        if (preg_match('#^/assets/[A-Za-z0-9._/-]+$#', $path) !== 1) {
            return self::text($path);
        }
        $file = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public'
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $version = '0';
        if (is_file($file)) {
            $mtime = filemtime($file);
            if (is_int($mtime) && $mtime > 0) {
                $version = (string) $mtime;
            }
        }

        return self::text($path) . '?v=' . self::text($version);
    }

    public static function passwordField(
        string $id,
        string $name,
        string $class = 'input',
        ?int $minlength = null,
        ?string $autocomplete = null,
        ?string $value = null,
    ): void {
        self::writePasswordField($id, $name, $class, '', $minlength, $autocomplete, $value);
    }

    public static function requiredPasswordField(
        string $id,
        string $name,
        string $class = 'input',
        ?int $minlength = null,
        ?string $autocomplete = null,
        ?string $value = null,
    ): void {
        self::writePasswordField($id, $name, $class, ' required', $minlength, $autocomplete, $value);
    }

    private static function writePasswordField(
        string $id,
        string $name,
        string $class,
        string $requiredAttr,
        ?int $minlength,
        ?string $autocomplete,
        ?string $value,
    ): void {
        $safeId = self::text($id);
        $classAttr = $class !== '' ? ' class="' . self::text($class) . '"' : '';
        $minAttr = $minlength !== null ? ' minlength="' . $minlength . '"' : '';
        $acAttr = ($autocomplete !== null && $autocomplete !== '')
            ? ' autocomplete="' . self::text($autocomplete) . '"'
            : '';
        $valueAttr = $value !== null ? ' value="' . self::text($value) . '"' : '';
        echo '<div class="password-field">';
        echo '<input type="password" id="' . $safeId . '" name="' . self::text($name) . '"'
            . $classAttr . $requiredAttr . $minAttr . $acAttr . $valueAttr . '>';
        echo '<button type="button" class="password-field__toggle" aria-label="Show password"'
            . ' aria-controls="' . $safeId . '" aria-pressed="false">';
        echo '<span class="password-field__icon password-field__icon--show" aria-hidden="true">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
            . '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        echo '</span>';
        echo '<span class="password-field__icon password-field__icon--hide" aria-hidden="true">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
            . '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94'
            . 'M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07'
            . 'a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        echo '</span>';
        echo '</button>';
        echo '</div>';
    }
}
