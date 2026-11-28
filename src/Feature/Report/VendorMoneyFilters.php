<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Http\Request;

/**
 * What a person chose on the vendor register or rent payments page: a date
 * range (a one-tap range or two dates), and the vendor, booth, way paid and
 * search. Anything else in the address is refused in plain words.
 */
final class VendorMoneyFilters
{
    private const PAID_BY = ['cash', 'card', 'check', 'split', 'sales', 'paid_ahead', 'waived'];

    /**
     * @param array{vendor: string, booth: string, paid_by: string, q: string} $chosen
     */
    private function __construct(
        public readonly ReportWindow $window,
        public readonly array $chosen,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param list<string> $allowed the filters this page takes besides the dates
     */
    public static function read(Request $request, string $today, array $allowed): self
    {
        $chosen = [
            'vendor' => $request->query('vendor'),
            'booth' => $request->query('booth'),
            'paid_by' => $request->query('paid_by'),
            'q' => mb_substr($request->query('q'), 0, 80),
        ];
        $window = (new ReportCalendar())->bounds($request, $today);
        $error = self::unexpected($request, [...$allowed, 'range', 'from', 'to']) ?? $window->error ?? self::refused($chosen);

        return new self($window, $chosen, $error);
    }

    /**
     * The page address for these filters with a different range, for the
     * one-tap range links and the download.
     */
    public function query(string $range = ''): string
    {
        $pairs = [];
        foreach ($this->chosen as $key => $value) {
            if ($value !== '') {
                $pairs[$key] = $value;
            }
        }
        if ($range !== '') {
            return http_build_query($pairs + ['range' => $range]);
        }
        $dates = $this->window->query;

        return http_build_query($pairs) . ($pairs !== [] && $dates !== '' ? '&' : '') . $dates;
    }

    /**
     * @param array{vendor: string, booth: string, paid_by: string, q: string} $chosen
     */
    private static function refused(array $chosen): ?string
    {
        if ($chosen['vendor'] !== '' && !VendorMoney::isId($chosen['vendor'])) {
            return 'Choose a vendor.';
        }
        if ($chosen['booth'] !== '' && !VendorMoney::isId($chosen['booth'])) {
            return 'Choose a booth.';
        }
        if ($chosen['paid_by'] !== '' && !in_array($chosen['paid_by'], self::PAID_BY, true)) {
            return 'Choose how it was paid.';
        }

        return null;
    }

    /**
     * @param list<string> $allowed
     */
    private static function unexpected(Request $request, array $allowed): ?string
    {
        foreach ($request->query as $key => $value) {
            if (!is_string($key) || !is_string($value) || !in_array($key, $allowed, true)) {
                return 'That filter is not on this page.';
            }
        }

        return null;
    }
}
