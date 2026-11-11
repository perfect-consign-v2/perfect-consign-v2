<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Scalar;

final class PersonPlace
{
    public static function lines(string $street, string $city, string $state, string $postal): string
    {
        $parts = [];
        $street = trim($street);
        if ($street !== '') {
            $parts[] = $street;
        }
        $place = self::cityLine($city, $state, $postal);
        if ($place !== '') {
            $parts[] = $place;
        }

        return implode("\n", $parts);
    }

    public static function cityLine(string $city, string $state, string $postal): string
    {
        $city = trim($city);
        $region = trim(trim($state) . ' ' . trim($postal));
        if ($city === '') {
            return $region;
        }
        if ($region === '') {
            return $city;
        }

        return $city . ', ' . $region;
    }
}
