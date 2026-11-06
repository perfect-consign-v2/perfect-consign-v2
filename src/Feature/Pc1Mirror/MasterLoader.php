<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Feature\Party\VendorDraft;

final class MasterLoader
{
    /** @var array<string, true> */
    private array $usedVendorCodes = [];

    /** @var array<string, true> */
    private array $usedBoothCodes = [];

    /** @var array<string, true> */
    private array $usedStoredNumbers = [];

    public function __construct(
        private readonly ItemMasterLoader $items = new ItemMasterLoader(),
        private readonly AssignmentMasterLoader $assignments = new AssignmentMasterLoader(),
    ) {
    }

    /**
     * @return array{
     *     vendors: array<string, string>,
     *     booths: array<string, string>,
     *     stored_values: array<string, string>,
     *     items: int,
     *     assignments: int
     * }
     */
    public function load(MirrorMall $mall, MirrorArtifact $artifact): array
    {
        $vendorMap = $this->vendors($mall, $this->objectRows($artifact->readJson('master/vendors.json')));
        $boothMap = $this->booths($mall, $this->objectRows($artifact->readJson('master/booths.json')));
        $assignmentCount = $this->assignments->load(
            $mall,
            $this->objectRows($artifact->readJson('master/assignments.json')),
            $vendorMap,
            $boothMap
        );
        $storedMap = $this->storedValues($mall, $this->objectRows($artifact->readJson('master/stored_values.json')));
        $itemCount = $this->items->load($mall, $this->objectRows($artifact->readJson('master/items.json')), $vendorMap);
        $this->settings($mall, MirrorFields::stringKeyed($artifact->readJson('master/settings.json')));

        $map = [
            'vendors' => $vendorMap,
            'booths' => $boothMap,
            'stored_values' => $storedMap,
            'items' => $itemCount,
            'assignments' => $assignmentCount,
        ];
        $artifact->writeJson('id-map.json', $map);

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function vendors(MirrorMall $mall, array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $pc1 = MirrorFields::text($row['pc1_id'] ?? null);
            $code = MirrorFields::text($row['code'] ?? null);
            if ($pc1 === '' || $code === '') {
                throw new MirrorException('vendor master row is incomplete');
            }
            $commission = null;
            if (array_key_exists('commission_bps', $row) && $row['commission_bps'] !== null) {
                $commission = MirrorFields::cents($row['commission_bps']);
                if ($commission < 0 || $commission > 10000) {
                    $commission = null;
                }
            }
            $code = $this->uniqueCode($code, $this->usedVendorCodes);
            $id = $mall->parties->addVendor(new VendorDraft([
                'code' => $code,
                'first_name' => MirrorFields::text($row['first_name'] ?? null, 'Vendor'),
                'last_name' => MirrorFields::text($row['last_name'] ?? null, 'Consignor'),
                'phone' => MirrorFields::text($row['phone'] ?? null),
                'email' => $this->email(MirrorFields::text($row['email'] ?? null)),
                'address' => MirrorFields::text($row['address'] ?? null),
                'agreement_starts_on' => '',
                'agreement_ends_on' => '',
                'tax_id' => '',
                'notes' => 'pc1:' . $pc1,
                'tax_exempt' => '',
                'tax_exempt_number' => '',
                'business_name' => '',
            ], $commission));
            if (($row['active'] ?? true) === false) {
                $mall->parties->setActive($id, false);
            }
            $map[$pc1] = $id;
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function booths(MirrorMall $mall, array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $pc1 = MirrorFields::text($row['pc1_id'] ?? null);
            $code = MirrorFields::text($row['code'] ?? null);
            if ($pc1 === '' || $code === '') {
                throw new MirrorException('booth master row is incomplete');
            }
            $rent = null;
            if (array_key_exists('asking_rent_minor', $row) && $row['asking_rent_minor'] !== null) {
                $asked = MirrorFields::cents($row['asking_rent_minor']);
                $rent = $asked >= 1 ? $asked : null;
            }
            $code = $this->uniqueCode($code, $this->usedBoothCodes);
            $mall->parties->addBooth($code, MirrorFields::text($row['name'] ?? null, $code), null, null, $rent);
            $found = $mall->statements->bound('SELECT id::text AS id FROM booths WHERE code = ?', [$code]);
            $id = MirrorFields::text($found[0]['id'] ?? null);
            if ($id === '') {
                throw new MirrorException('booth was not created: ' . $code);
            }
            $map[$pc1] = $id;
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function storedValues(MirrorMall $mall, array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $pc1 = MirrorFields::text($row['pc1_id'] ?? null);
            $kind = MirrorFields::text($row['kind'] ?? null);
            $number = MirrorFields::text($row['number'] ?? null);
            if (
                $pc1 === ''
                || $number === ''
                || !in_array($kind, ['gift_certificate', 'gift_card', 'store_credit'], true)
            ) {
                continue;
            }
            $number = $this->uniqueCode($kind . ':' . $number, $this->usedStoredNumbers);
            $number = substr($number, strlen($kind) + 1);
            $created = $mall->statements->bound(
                'INSERT INTO stored_values (kind, number) VALUES (?, ?) RETURNING id::text AS id',
                [$kind, $number]
            );
            $id = MirrorFields::text($created[0]['id'] ?? null);
            if ($id === '') {
                throw new MirrorException('stored value was not created');
            }
            $map[$pc1] = $id;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settings(MirrorMall $mall, array $settings): void
    {
        $taxBps = $this->taxBps($settings);
        if ($taxBps > 0) {
            $mall->statements->change('UPDATE mall SET tax_bps = ?', [$taxBps]);
        }
        $zone = MirrorFields::text($settings['store_timezone'] ?? null);
        if ($zone === '') {
            return;
        }
        try {
            $mall->statements->change('UPDATE mall SET time_zone = ?', [$zone]);
        } catch (\Throwable) {
            // Keep seeded zone when PC1 name is not a Postgres time zone.
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function taxBps(array $settings): int
    {
        $taxBps = MirrorFields::cents($settings['tax_bps'] ?? 0);
        if ($taxBps >= 1) {
            return $taxBps;
        }
        $rates = $settings['location_tax_rates'] ?? null;
        if (!is_array($rates) || $rates === []) {
            return 0;
        }
        $first = $rates[0] ?? null;
        if (is_array($first) && isset($first['rate']) && is_numeric($first['rate'])) {
            return (int) round(((float) $first['rate']) * 10000);
        }
        if (is_numeric($first)) {
            return (int) round(((float) $first) * 10000);
        }

        return 0;
    }

    private function email(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $trimmed) !== 1) {
            return '';
        }

        return $trimmed;
    }

    /**
     * @param array<string, true> $used
     */
    private function uniqueCode(string $code, array &$used): string
    {
        $base = substr($code, 0, 40);
        $candidate = $base;
        $n = 2;
        while (isset($used[$candidate])) {
            $suffix = '-' . $n;
            $candidate = substr($base, 0, max(1, 40 - strlen($suffix))) . $suffix;
            $n++;
            if ($n > 9999) {
                throw new MirrorException('could not uniquify code ' . $code);
            }
        }
        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function objectRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = MirrorFields::stringKeyed($row);
            }
        }

        return $out;
    }
}
