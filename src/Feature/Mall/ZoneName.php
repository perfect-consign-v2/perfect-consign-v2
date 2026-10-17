<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class ZoneName
{
    /** @var array<string, string> */
    private const NAMED = [
        'America/Los_Angeles' => 'Pacific Time (Los Angeles)',
        'America/Vancouver' => 'Pacific Time (Vancouver)',
        'America/Tijuana' => 'Pacific Time (Tijuana)',
        'America/Denver' => 'Mountain Time (Denver)',
        'America/Edmonton' => 'Mountain Time (Edmonton)',
        'America/Phoenix' => 'Mountain Time (Phoenix)',
        'America/Chicago' => 'Central Time (Chicago)',
        'America/Winnipeg' => 'Central Time (Winnipeg)',
        'America/Mexico_City' => 'Central Time (Mexico City)',
        'America/New_York' => 'Eastern Time (New York)',
        'America/Toronto' => 'Eastern Time (Toronto)',
        'America/Detroit' => 'Eastern Time (Detroit)',
        'America/Halifax' => 'Atlantic Time (Halifax)',
        'America/St_Johns' => 'Newfoundland Time (St. Johns)',
        'America/Anchorage' => 'Alaska Time (Anchorage)',
        'Pacific/Honolulu' => 'Hawaii Time (Honolulu)',
    ];

    public static function label(string $zone): string
    {
        if (isset(self::NAMED[$zone])) {
            return self::NAMED[$zone];
        }
        $slash = strrchr($zone, '/');
        $city = str_replace('_', ' ', $slash === false ? $zone : substr($slash, 1));
        if ($city === '') {
            return $zone;
        }

        return $city;
    }
}
