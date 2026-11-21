<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use DateTimeImmutable;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;

final class ReportSpan
{
    private const SALES_GROUPS = ['day', 'tender', 'vendor', 'cashier', 'hour', 'category'];

    private readonly ReportCalendar $calendar;

    public function __construct()
    {
        $this->calendar = new ReportCalendar();
    }

    public function sales(Request $request, string $today): ReportWindow
    {
        $raw = $this->raw($request);
        $unexpected = $this->unexpected($request, ['group', 'range', 'from', 'to', 'day']);
        if ($unexpected !== null) {
            return ReportWindow::failed($unexpected, $raw);
        }
        $group = $request->query('group');
        if ($group === '') {
            $group = 'day';
        }
        if (!in_array($group, self::SALES_GROUPS, true)) {
            return ReportWindow::failed('That grouping is not on this page.', $raw);
        }
        if ($group === 'hour') {
            return $this->calendar->hour($request, $today, $raw);
        }
        if ($request->query('day') !== '') {
            return ReportWindow::failed('That filter is not on this page.', $raw);
        }
        $bounds = $this->calendar->bounds($request, $today);
        if ($bounds->isFailed()) {
            return $bounds;
        }

        return ReportWindow::ready(
            $bounds->from,
            $bounds->to,
            $bounds->label,
            $group,
            'group=' . rawurlencode($group) . ($bounds->query === '' ? '' : '&' . $bounds->query),
        );
    }

    public function ranged(Request $request, string $today): ReportWindow
    {
        $raw = $this->raw($request);
        $unexpected = $this->unexpected($request, ['range', 'from', 'to']);
        if ($unexpected !== null) {
            return ReportWindow::failed($unexpected, $raw);
        }

        return $this->calendar->bounds($request, $today);
    }

    public function month(Request $request, string $today): ReportWindow
    {
        $raw = $this->raw($request);
        $unexpected = $this->unexpected($request, ['month', 'print']);
        if ($unexpected !== null) {
            return ReportWindow::failed($unexpected, $raw);
        }
        $print = $request->query('print');
        if ($print !== '' && $print !== '1') {
            return ReportWindow::failed('That filter is not on this page.', $raw);
        }
        $month = $request->query('month');
        if ($month === '') {
            $month = substr($today, 0, 7);
        }
        try {
            $bounds = (new ReportMonth())->bounds($month);
        } catch (\InvalidArgumentException) {
            return ReportWindow::failed('Enter a real month.', $raw);
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $bounds['from']);
        $label = $start instanceof DateTimeImmutable ? $start->format('F Y') : $month;

        return ReportWindow::ready($bounds['from'], $bounds['to'], $label, 'none', 'month=' . $month);
    }

    public function pinned(Request $request, string $today): ReportWindow
    {
        if ($request->query !== []) {
            return ReportWindow::failed('That filter is not on this page.', $this->raw($request));
        }

        return ReportWindow::ready($today, $today, 'As of ' . View::date($today), 'none', '');
    }

    /**
     * @param list<string> $allowed
     */
    private function unexpected(Request $request, array $allowed): ?string
    {
        foreach ($request->query as $key => $value) {
            if (!is_string($key) || !is_string($value) || !in_array($key, $allowed, true)) {
                return 'That filter is not on this page.';
            }
        }

        return null;
    }

    private function raw(Request $request): string
    {
        $pairs = [];
        foreach ($request->query as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $pairs[$key] = $value;
            }
        }

        return http_build_query($pairs);
    }
}
