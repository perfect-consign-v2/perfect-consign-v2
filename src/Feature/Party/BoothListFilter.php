<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/**
 * Search and standing filters for the booth list.
 */
final class BoothListFilter
{
    /**
     * @param list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   status_tip: ?string,
     *   incoming_vendor_id: ?string,
     *   incoming_vendor_code: ?string,
     *   incoming_tip: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }> $rows
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   status_tip: ?string,
     *   incoming_vendor_id: ?string,
     *   incoming_vendor_code: ?string,
     *   incoming_tip: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }>
     */
    public function matching(array $rows, string $query): array
    {
        $needle = strtolower($query);
        if ($needle === '') {
            return $rows;
        }
        $visible = [];
        foreach ($rows as $row) {
            $named = str_contains(strtolower($row['code']), $needle) || str_contains(strtolower($row['name']), $needle);
            if ($named) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * @param list<array{status: string, incoming_vendor_id: ?string}> $rows
     * @return array{available: int, occupied: int, ending: int, incoming: int}
     */
    public function legend(array $rows): array
    {
        $counts = ['available' => 0, 'occupied' => 0, 'ending' => 0, 'incoming' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($counts) as $status) {
                if ($this->shows($row, $status)) {
                    $counts[$status]++;
                }
            }
        }

        return $counts;
    }

    /**
     * @param list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   status_tip: ?string,
     *   incoming_vendor_id: ?string,
     *   incoming_vendor_code: ?string,
     *   incoming_tip: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }> $rows
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   width: int,
     *   depth: int,
     *   status: string,
     *   status_label: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   status_tip: ?string,
     *   incoming_vendor_id: ?string,
     *   incoming_vendor_code: ?string,
     *   incoming_tip: ?string,
     *   rent_minor: ?int,
     *   open_minor: ?int,
     *   placed: bool,
     *   deletable: bool
     * }>
     */
    public function narrow(array $rows, string $standing): array
    {
        if ($standing === '') {
            return $rows;
        }
        $visible = [];
        foreach ($rows as $row) {
            if ($this->shows($row, $standing)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * @param list<array{id: string, deletable: bool}> $rows
     */
    public function confirming(array $rows, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($rows as $row) {
            if (strtolower($row['id']) === $needle && $row['deletable']) {
                return $row['id'];
            }
        }

        return '';
    }

    /**
     * @param array{status: string, incoming_vendor_id: ?string} $row
     */
    private function shows(array $row, string $status): bool
    {
        if ($row['status'] === $status) {
            return true;
        }

        return $status === 'incoming' && $row['incoming_vendor_id'] !== null;
    }
}
