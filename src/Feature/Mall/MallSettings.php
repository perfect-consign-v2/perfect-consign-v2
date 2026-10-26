<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeImmutable;
use DateTimeZone;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MallSettings
{
    public function __construct(
        private readonly Statements $statements,
        private readonly CompanyStore $company,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getCompanyLines(): array
    {
        return $this->company->lines();
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->timeZone())))->format('Y-m-d');
    }

    public function timeZone(): string
    {
        return RowValue::string($this->row()['time_zone'] ?? null);
    }

    /**
     * @return array{width: int, depth: int}
     */
    public function boothSize(): array
    {
        $row = $this->row();

        return [
            'width' => RowValue::integer($row['booth_width_ft'] ?? null),
            'depth' => RowValue::integer($row['booth_depth_ft'] ?? null),
        ];
    }

    /**
     * @return array{width: int, depth: int}
     */
    public function floorSize(): array
    {
        $row = $this->row();

        return [
            'width' => RowValue::integer($row['floor_width_ft'] ?? null),
            'depth' => RowValue::integer($row['floor_depth_ft'] ?? null),
        ];
    }

    public function storeTake(): int
    {
        return RowValue::integer($this->row()['commission_bps'] ?? null);
    }

    public function taxRate(): int
    {
        return RowValue::integer($this->row()['tax_bps'] ?? null);
    }

    public function floorDays(): ?int
    {
        $days = $this->row()['floor_days'] ?? null;
        if ($days === null) {
            return null;
        }

        return RowValue::integer($days);
    }

    public function cashRoundMinor(): int
    {
        return RowValue::integer($this->row()['cash_round_minor'] ?? 0);
    }

    public function getCashRoundText(): string
    {
        return (string) $this->cashRoundMinor();
    }


    public function getLabelStock(): string
    {
        return RowValue::string($this->row()['label_stock'] ?? null);
    }

    public function setLabelStock(string $stock): void
    {
        $this->statements->change('UPDATE mall SET label_stock = ?', [$stock]);
    }

    public function getReceiptFooter(): string
    {
        return RowValue::string($this->row()['receipt_footer'] ?? null);
    }

    public function setReceiptFooter(string $footer): void
    {
        $this->statements->change('UPDATE mall SET receipt_footer = ?', [ReceiptFooter::text($footer)]);
    }

    public function getReceiptMailVia(): string
    {
        $via = RowValue::string($this->mailRow()['receipt_mail_via'] ?? null);

        return $via === 'gmail' ? 'gmail' : 'server';
    }

    public function getGmailAddress(): string
    {
        $address = $this->mailRow()['gmail_address'] ?? null;

        return is_string($address) ? $address : '';
    }

    public function hasGmailPassword(): bool
    {
        $password = $this->mailRow()['gmail_app_password'] ?? null;

        return is_string($password) && $password !== '';
    }

    public function setReceiptMail(string $via, string $address, string $password): void
    {
        (new ReceiptMailSettings($this->statements))->save($via, $address, $password);
    }

    /**
     * @return array<string, mixed>
     */
    private function mailRow(): array
    {
        $rows = $this->statements->rows('SELECT receipt_mail_via, gmail_address, gmail_app_password FROM mall');
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return $row;
    }

    public function isRentalsEnabled(): bool
    {
        return MallGuard::flag($this->row()['booth_rentals_enabled'] ?? null);
    }

    public function isConsignmentEnabled(): bool
    {
        return MallGuard::flag($this->row()['consignment_enabled'] ?? null);
    }

    public function isConsignmentOnly(): bool
    {
        return RowValue::string($this->row()['operating_mode'] ?? null) === 'consignor';
    }

    public function getPartyLabel(string $vendor, string $consignor): string
    {
        if ($this->isConsignmentOnly()) {
            return $consignor;
        }

        return $vendor;
    }

    public function isStoreStockEnabled(): bool
    {
        return MallGuard::flag($this->row()['store_stock_enabled'] ?? null);
    }

    public function getCycleDay(): int
    {
        $day = RowValue::integer($this->row()['rent_cycle_day'] ?? null);

        return $day >= 1 && $day <= 28 ? $day : 1;
    }

    public function enabled(string $flag): bool
    {
        if (!in_array($flag, ['use_color', 'use_category', 'use_size', 'use_condition', 'use_department', 'use_brand'], true)) {
            throw new \InvalidArgumentException('unknown catalog flag');
        }

        return MallGuard::flag($this->row()[$flag] ?? null);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function getCatalogLinks(): array
    {
        $links = [];
        foreach (NameList::all() as $list) {
            if ($this->enabled($list->flag)) {
                $links[] = ['/' . $list->path, $list->plural, 'i-tag'];
            }
        }

        return $links;
    }

    /**
     * @return list<NameList>
     */
    public function getEnabledLists(): array
    {
        $lists = [];
        foreach (NameList::all() as $list) {
            if ($this->enabled($list->flag)) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    public function save(
        string $timeZone,
        MallWrite $write,
        int $storeTake,
        ?int $floorDays,
        string $cashRound,
        int $taxRate,
    ): void {
        MallGuard::requireZone($timeZone);
        MallGuard::requireFeet($write->boothWidth, $write->boothDepth, 'booth size is whole feet');
        MallGuard::requireFeet($write->floorWidth, $write->floorDepth, 'floor size is whole feet');
        MallGuard::requireTake($storeTake);
        MallGuard::requireTax($taxRate);
        MallGuard::guardDays($floorDays);
        $step = CashRound::parse($cashRound);
        $this->statements->change(
            'UPDATE mall SET time_zone = ?, booth_width_ft = ?, booth_depth_ft = ?, floor_width_ft = ?, floor_depth_ft = ?, commission_bps = ?, floor_days = ?, cash_round_minor = ?, tax_bps = ?, use_color = ?::boolean, use_category = ?::boolean, use_size = ?::boolean, use_condition = ?::boolean, use_department = ?::boolean, use_brand = ?::boolean',
            [
                $timeZone,
                $write->boothWidth,
                $write->boothDepth,
                $write->floorWidth,
                $write->floorDepth,
                $storeTake,
                $floorDays,
                $step,
                $taxRate,
                ...$write->flags->stored(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->statements->rows(
            'SELECT time_zone, booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft, commission_bps, floor_days, cash_round_minor, tax_bps, use_color, use_category, use_size, use_condition, use_department, use_brand, label_stock, booth_rentals_enabled, rent_cycle_day, consignment_enabled, store_stock_enabled, receipt_footer, operating_mode FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return $row;
    }
}
