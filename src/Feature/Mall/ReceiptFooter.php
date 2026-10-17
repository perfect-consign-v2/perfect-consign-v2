<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class ReceiptFooter
{
    public const LIMIT = 400;

    public static function text(string $raw): string
    {
        $text = str_replace("\r\n", "\n", trim($raw));
        $text = str_replace("\r", "\n", $text);
        if (self::length($text) > self::LIMIT) {
            throw new \InvalidArgumentException('receipt footer is 400 characters or less');
        }

        return $text;
    }

    private static function length(string $text): int
    {
        $heads = preg_replace('/[\x80-\xBF]/', '', $text);
        if (!is_string($heads)) {
            throw new \InvalidArgumentException('receipt footer is 400 characters or less');
        }

        return strlen($heads);
    }
}
