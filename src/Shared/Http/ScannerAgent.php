<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class ScannerAgent
{
    private const TOKENS = 'zap|sqlmap|nikto|nuclei|nessus|nmap|masscan|dirbuster|gobuster|ffuf|feroxbuster|wpscan|acunetix|openvas|zgrab';

    public static function matches(string $agent): bool
    {
        if ($agent === '') {
            return false;
        }

        return preg_match('/(?:^|[^a-z0-9])(?:' . self::TOKENS . ')(?:[^a-z0-9]|$)/i', $agent) === 1;
    }
}
