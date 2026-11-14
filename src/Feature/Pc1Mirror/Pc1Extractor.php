<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

final class Pc1Extractor
{
    public function __construct(
        private readonly Pc1ReadOnlyConnection $source,
        private readonly Pc1RentOpen $rent = new Pc1RentOpen(),
        private readonly Pc1GiftExtract $gifts = new Pc1GiftExtract(),
        private readonly Pc1SettingsExtract $settingsReader = new Pc1SettingsExtract(),
    ) {
    }

    public function extract(string $slug, string $artifactRoot): MirrorArtifact
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new MirrorException('source slug is invalid');
        }
        $pdo = $this->source->pdo();
        $tenant = $this->resolveTenant($pdo, $slug);
        $schema = $tenant['schema'];
        $pdo->exec('SET search_path TO "' . $schema . '", public');
        $asOf = gmdate('Ymd\THis\Z');
        $artifact = MirrorArtifact::create($artifactRoot, $slug, $asOf);
        $settings = $this->settingsReader->read($tenant['settings'], $pdo);
        $vendors = $this->vendors($pdo);
        $booths = $this->booths($pdo);
        $assignments = $this->assignments($pdo);
        $items = $this->items($pdo);
        $stored = $this->gifts->all($pdo);
        $balances = $this->balances($pdo, $settings['booth_rent_cycle_day'], $vendors, $stored);
        $artifact->writeJson('manifest.json', [
            'extractor_version' => MirrorConstants::EXTRACTOR_VERSION,
            'source_host' => $this->source->label(),
            'slug' => $slug,
            'schema' => $schema,
            'tenant_id' => $tenant['tenant_id'],
            'as_of_utc' => $asOf,
            'read_only' => true,
        ]);
        $artifact->writeJson('balances.json', $balances);
        $artifact->writeJson('master/vendors.json', $vendors);
        $artifact->writeJson('master/booths.json', $booths);
        $artifact->writeJson('master/assignments.json', $assignments);
        $artifact->writeJson('master/items.json', $items);
        $artifact->writeJson('master/stored_values.json', $stored);
        $artifact->writeJson('master/settings.json', $settings);
        $artifact->writeJson('manifest.json', [
            'extractor_version' => MirrorConstants::EXTRACTOR_VERSION,
            'source_host' => $this->source->label(),
            'slug' => $slug,
            'schema' => $schema,
            'tenant_id' => $tenant['tenant_id'],
            'as_of_utc' => $asOf,
            'read_only' => true,
            'sha256' => $artifact->checksum(),
        ]);

        return $artifact;
    }

    /**
     * @return array{tenant_id: string, schema: string, settings: mixed}
     */
    private function resolveTenant(PDO $pdo, string $slug): array
    {
        $statement = $pdo->prepare(
            'SELECT tenant_id::text AS tenant_id, settings
             FROM public.tenants
             WHERE lower(slug) = lower(?)'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new MirrorException('PC1 tenant slug was not found');
        }
        $tenantId = MirrorFields::text($row['tenant_id'] ?? null);
        if ($tenantId === '') {
            throw new MirrorException('PC1 tenant slug was not found');
        }
        $schema = 'tenant_' . str_replace('-', '_', strtolower($tenantId));
        if (preg_match('/^tenant_[0-9a-f_]{32,36}$/', $schema) !== 1) {
            throw new MirrorException('PC1 tenant schema is invalid');
        }

        return [
            'tenant_id' => $tenantId,
            'schema' => $schema,
            'settings' => $row['settings'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vendors(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT c.consignor_id::text AS pc1_id,
                    COALESCE(NULLIF(c.custom_id, ''), left(replace(c.consignor_id::text, '-', ''), 8)) AS code,
                    COALESCE(NULLIF(p.first_name, ''), 'Vendor') AS first_name,
                    COALESCE(NULLIF(p.last_name, ''), 'Consignor') AS last_name,
                    COALESCE(p.phone, '') AS phone,
                    COALESCE(p.email, '') AS email,
                    COALESCE(p.address, '') AS address,
                    COALESCE(c.default_commission_pct, 0)::text AS commission_pct,
                    COALESCE(c.status, 'active') AS status
             FROM consignors c
             LEFT JOIN persons p ON p.party_id = c.party_id
             ORDER BY code"
        );
        $listed = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($listed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pct = (float) MirrorFields::text($row['commission_pct'] ?? null, '0');
            $out[] = [
                'pc1_id' => MirrorFields::text($row['pc1_id'] ?? null),
                'code' => $this->code(MirrorFields::text($row['code'] ?? null)),
                'first_name' => MirrorFields::text($row['first_name'] ?? null, 'Vendor'),
                'last_name' => MirrorFields::text($row['last_name'] ?? null, 'Consignor'),
                'phone' => MirrorFields::text($row['phone'] ?? null),
                'email' => MirrorFields::text($row['email'] ?? null),
                'address' => MirrorFields::text($row['address'] ?? null),
                'commission_bps' => (int) round($pct * 100),
                'active' => strtolower(MirrorFields::text($row['status'] ?? null)) !== 'inactive',
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function booths(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT booth_id::text AS pc1_id,
                    booth_name AS code,
                    booth_name AS name,
                    COALESCE(monthly_rent, 0)::text AS monthly_rent,
                    COALESCE(status, 'active') AS status
             FROM booths
             WHERE COALESCE(status, 'active') <> 'deleted'
             ORDER BY booth_name"
        );
        $listed = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($listed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'pc1_id' => MirrorFields::text($row['pc1_id'] ?? null),
                'code' => $this->code(MirrorFields::text($row['code'] ?? null)),
                'name' => MirrorFields::text($row['name'] ?? null),
                'asking_rent_minor' => Pc1Money::dollarsToCents(MirrorFields::text($row['monthly_rent'] ?? null, '0')),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignments(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT consignor_booth_assignment_id::text AS pc1_id,
                    booth_id::text AS pc1_booth_id,
                    consignor_id::text AS pc1_consignor_id,
                    started_at::date::text AS starts_on,
                    CASE WHEN ended_at IS NULL THEN '' ELSE ended_at::date::text END AS ends_on,
                    COALESCE(monthly_rent, 0)::text AS monthly_rent
             FROM consignor_booth_assignments
             ORDER BY started_at"
        );
        $listed = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($listed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'pc1_id' => MirrorFields::text($row['pc1_id'] ?? null),
                'pc1_booth_id' => MirrorFields::text($row['pc1_booth_id'] ?? null),
                'pc1_consignor_id' => MirrorFields::text($row['pc1_consignor_id'] ?? null),
                'starts_on' => MirrorFields::text($row['starts_on'] ?? null),
                'ends_on' => MirrorFields::text($row['ends_on'] ?? null),
                'rent_minor' => Pc1Money::dollarsToCents(MirrorFields::text($row['monthly_rent'] ?? null, '0')),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT item_id::text AS pc1_id,
                    COALESCE(NULLIF(sku, ''), left(replace(item_id::text, '-', ''), 12)) AS code,
                    COALESCE(barcode, '') AS barcode,
                    consignor_id::text AS pc1_consignor_id,
                    COALESCE(description, '') AS description,
                    COALESCE(price, 0)::text AS price,
                    COALESCE(quantity, 1)::text AS quantity,
                    COALESCE(status, 'available') AS status,
                    COALESCE(intake_date::text, CURRENT_DATE::text) AS intake_on
             FROM items
             WHERE COALESCE(status, 'available') IN ('available', 'active', 'on_floor')
             ORDER BY sku NULLS LAST
             LIMIT 5000"
        );
        $listed = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($listed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'pc1_id' => MirrorFields::text($row['pc1_id'] ?? null),
                'code' => $this->code(MirrorFields::text($row['code'] ?? null)),
                'barcode' => MirrorFields::text($row['barcode'] ?? null),
                'pc1_consignor_id' => MirrorFields::text($row['pc1_consignor_id'] ?? null),
                'description' => mb_substr(MirrorFields::text($row['description'] ?? null, 'Item'), 0, 200),
                'price_minor' => Pc1Money::dollarsToCents(MirrorFields::text($row['price'] ?? null, '0')),
                'quantity' => max(1, MirrorFields::cents(MirrorFields::text($row['quantity'] ?? null, '1'))),
                'intake_on' => MirrorFields::text($row['intake_on'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $vendors
     * @param list<array<string, mixed>> $stored
     * @return array<string, mixed>
     */
    private function balances(PDO $pdo, int $cycleDay, array $vendors, array $stored): array
    {
        $payable = [];
        $rows = $pdo->query(
            'SELECT consignor_id::text AS pc1_id, COALESCE(balance, 0)::text AS balance
             FROM consignor_balances'
        );
        $listed = $rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($listed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cents = Pc1Money::dollarsToCents(MirrorFields::text($row['balance'] ?? null, '0'));
            if ($cents === 0) {
                continue;
            }
            $payable[] = [
                'pc1_id' => MirrorFields::text($row['pc1_id'] ?? null),
                'cents' => $cents,
            ];
        }
        $receivable = [];
        foreach ($payable as $row) {
            if ($row['cents'] >= 0) {
                continue;
            }
            $receivable[] = [
                'pc1_id' => $row['pc1_id'],
                'cents' => -$row['cents'],
            ];
        }
        $payable = array_values(array_filter(
            $payable,
            static fn (array $row): bool => $row['cents'] > 0
        ));
        $rent = $this->rent->asOfToday($pdo, $cycleDay);
        $tax = $this->salesTaxPayable($pdo);
        $cash = $this->accountBalance($pdo, '1000');
        $gifts = [
            'gift_certificate' => 0,
            'gift_card' => 0,
            'store_credit' => 0,
        ];
        foreach ($stored as $row) {
            $kind = MirrorFields::text($row['kind'] ?? null);
            if (!isset($gifts[$kind])) {
                continue;
            }
            $gifts[$kind] += MirrorFields::cents($row['balance_minor'] ?? 0);
        }

        return [
            'vendor_payable' => $payable,
            'vendor_receivable' => $receivable,
            'booth_rent' => $rent,
            'sales_tax_payable_cents' => $tax,
            'cash_cents' => max(0, $cash),
            'gift_certificate_cents' => $gifts['gift_certificate'],
            'gift_card_cents' => $gifts['gift_card'],
            'store_credit_cents' => $gifts['store_credit'],
            'vendor_count' => count($vendors),
        ];
    }

    private function salesTaxPayable(PDO $pdo): int
    {
        $gl = $this->accountBalance($pdo, '2000');
        if ($gl !== 0) {
            return max(0, $gl);
        }
        $rows = $pdo->query(
            "SELECT COALESCE(sum(tax_amount), 0)::text AS total
             FROM sales
             WHERE status IN ('completed', 'complete', 'paid')
               AND COALESCE(voided, false) = false"
        );
        if ($rows === false) {
            throw new \RuntimeException('sales tax payable query failed');
        }
        $total = $rows->fetchColumn();

        return max(0, Pc1Money::dollarsToCents(is_string($total) ? $total : '0'));
    }

    private function accountBalance(PDO $pdo, string $code): int
    {
        $exists = $pdo->query("SELECT to_regclass('journal_lines')");
        if ($exists === false) {
            throw new \RuntimeException('journal_lines existence check failed');
        }
        if ($exists->fetchColumn() === null) {
            return 0;
        }
        $statement = $pdo->prepare(
            'SELECT COALESCE(sum(jl.credit_minor - jl.debit_minor), 0)::text AS balance
             FROM journal_lines jl
             JOIN accounts a ON a.account_id = jl.account_id
             WHERE a.code = ?'
        );
        try {
            $statement->execute([$code]);
        } catch (\PDOException $error) {
            throw new \RuntimeException('account balance query failed for ' . $code, 0, $error);
        }
        $balance = $statement->fetchColumn();
        if (!is_string($balance) || preg_match('/^-?\d+$/', $balance) !== 1) {
            throw new \RuntimeException('account balance is not numeric for ' . $code);
        }

        return (int) $balance;
    }

    private function code(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'X';
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $trimmed) ?? 'X';

        return substr($safe, 0, 40);
    }
}
