<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use PDOStatement;

/**
 * Statements stand-in for controller branches. Query text selects the row shape.
 */
final class ScriptedStatements extends Statements
{
    public int $affectedCount = 1;

    public bool $affectedRefuses = false;

    public bool $transactionRefuses = false;

    public bool $invokeTransaction = true;

    public string $partyName = 'Pat';

    public bool $voidedChecks = false;

    /** @var array<string, mixed>|null */
    public ?array $paidRun = null;

    /** @var list<array<string, mixed>> */
    public array $movement = [];

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @param list<mixed> $params
     */
    public function change(string $sql, array $params): void
    {
    }

    /**
     * @param list<mixed> $params
     */
    public function affected(string $sql, array $params): int
    {
        if ($this->affectedRefuses) {
            throw new DatabaseRefusal('refused');
        }

        return $this->affectedCount;
    }

    public function transaction(callable $work): void
    {
        if ($this->transactionRefuses) {
            throw new DatabaseRefusal('refused');
        }
        if ($this->invokeTransaction) {
            $work();
        }
    }

    public function isolated(callable $work): void
    {
        $work();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        if (str_contains($sql, 'booth_width_ft')) {
            return [$this->mallRow()];
        }
        if (str_contains($sql, 'SELECT time_zone FROM mall')) {
            return [['time_zone' => 'America/Los_Angeles']];
        }
        if (str_contains($sql, 'rent_cycle_day FROM mall')) {
            return [['rent_cycle_day' => 1]];
        }
        if (str_contains($sql, 'tax_bps, cash_round_minor')) {
            return [['tax_bps' => 0, 'cash_round_minor' => 0]];
        }
        if (str_contains($sql, 'require_second_signature')) {
            return [['require_second_signature' => false]];
        }
        if (str_contains($sql, "ticket.status = 'open'")) {
            return [['id' => MallDoor::DRAWER, 'name' => 'Front']];
        }
        if (str_contains($sql, 'FROM payout_runs AS run') && $this->paidRun !== null) {
            return [$this->paidRun];
        }

        return [];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function bound(string $sql, array $params): array
    {
        if (str_contains($sql, 'stall_party_display_name')) {
            $id = $params[0] ?? '';

            return [[
                'id' => is_string($id) ? $id : '',
                'name' => $this->partyName,
                'kind' => 'person',
            ]];
        }
        if (str_contains($sql, 'AS check_void')) {
            return [['check_void' => $this->voidedChecks ? 'voided' : '']];
        }
        if (str_contains($sql, 'stall_reverse_payout_run')) {
            return [['id' => MallDoor::JOURNAL]];
        }
        if (str_contains($sql, 'accounting_periods')) {
            return [['ends_on' => '2026-12-31']];
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(PDOStatement $statement): array
    {
        return $this->movement;
    }

    /**
     * @return array<string, mixed>
     */
    private function mallRow(): array
    {
        return [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 4000,
            'floor_days' => 60,
            'cash_round_minor' => 0,
            'tax_bps' => 800,
            'use_color' => false,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => 'avery_8160',
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'consignment_enabled' => true,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
            'operating_mode' => 'both',
        ];
    }
}
