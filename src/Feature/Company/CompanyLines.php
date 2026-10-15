<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Company;

final class CompanyLines
{
    /**
     * @param array{street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string} $contact
     * @return list<string>
     */
    public function printed(array $contact): array
    {
        $lines = [];
        $this->add($lines, $contact['street']);
        $this->add($lines, $contact['street2']);
        $this->add($lines, $this->place($contact['city'], $contact['state'], $contact['postal']));
        $this->add($lines, $contact['phone']);
        $this->add($lines, $contact['email']);

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function add(array &$lines, string $line): void
    {
        if ($line === '') {
            return;
        }
        $lines[] = $line;
    }

    private function place(string $city, string $state, string $postal): string
    {
        $region = trim($state . ' ' . $postal);
        if ($city === '') {
            return $region;
        }
        if ($region === '') {
            return $city;
        }

        return $city . ', ' . $region;
    }
}
