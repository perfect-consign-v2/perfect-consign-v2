<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;

final class ReportCalendar
{
    private const RANGES = ['today', 'yesterday', 'this_week', 'this_month', 'last_month'];

    private readonly ReportPreset $preset;

    public function __construct()
    {
        $this->preset = new ReportPreset();
    }

    public function hour(Request $request, string $today, string $raw): ReportWindow
    {
        $range = $request->query('range');
        $day = $request->query('day');
        if ($this->hourRejected($request, $range, $day)) {
            return ReportWindow::failed('An hourly report is one day.', $raw);
        }
        $date = $this->hourDate($today, $range, $day);
        if ($date === null) {
            return ReportWindow::failed('Enter a real date.', $raw);
        }

        return ReportWindow::ready($date, $date, View::date($date), 'hour', $this->hourQuery($range, $day, $date));
    }

    public function bounds(Request $request, string $today): ReportWindow
    {
        $raw = $this->raw($request);
        $range = $request->query('range');
        $from = $request->query('from');
        $to = $request->query('to');
        if ($this->mixed($range, $from, $to)) {
            return ReportWindow::failed('Choose a range or two dates.', $raw);
        }
        if ($range === '' && $from === '' && $to === '') {
            [$start, $end] = $this->preset->range('this_month', $today);

            return $this->span($start, $end, 'range=this_month');
        }
        if ($range !== '') {
            return $this->named($range, $today, $raw);
        }

        return $this->dated($from, $to, $raw);
    }

    private function hourRejected(Request $request, string $range, string $day): bool
    {
        if ($request->query('from') !== '' || $request->query('to') !== '') {
            return true;
        }
        if ($range !== '' && $day !== '') {
            return true;
        }

        return $range !== '' && $range !== 'today' && $range !== 'yesterday';
    }

    private function hourDate(string $today, string $range, string $day): ?string
    {
        if ($day !== '') {
            return $this->day($day);
        }
        if ($range === 'yesterday') {
            return $this->preset->shift($today, '-1 day');
        }

        return $today;
    }

    private function hourQuery(string $range, string $day, string $date): string
    {
        if ($day !== '') {
            return 'group=hour&day=' . $date;
        }

        return 'group=hour&range=' . ($range === '' ? 'today' : $range);
    }

    private function mixed(string $range, string $from, string $to): bool
    {
        return $range !== '' && ($from !== '' || $to !== '');
    }

    private function dated(string $from, string $to, string $raw): ReportWindow
    {
        if ($from === '' || $to === '') {
            return ReportWindow::failed('Choose a range or two dates.', $raw);
        }
        $start = $this->day($from);
        $end = $this->day($to);
        if ($start === null || $end === null) {
            return ReportWindow::failed('Enter a real date.', $raw);
        }
        if ($end < $start) {
            return ReportWindow::failed('To is before From.', $raw);
        }

        return $this->span($start, $end, 'from=' . $start . '&to=' . $end);
    }

    private function named(string $range, string $today, string $raw): ReportWindow
    {
        if (!in_array($range, self::RANGES, true)) {
            return ReportWindow::failed('Choose a range or two dates.', $raw);
        }
        [$start, $end] = $this->preset->range($range, $today);

        return $this->span($start, $end, 'range=' . $range);
    }

    private function span(string $from, string $to, string $query): ReportWindow
    {
        $label = $from === $to
            ? View::date($from)
            : View::date($from) . ' through ' . View::date($to);

        return ReportWindow::ready($from, $to, $label, 'none', $query);
    }

    private function day(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $found) !== 1) {
            return null;
        }
        $year = (int) $found[1];
        $month = (int) $found[2];
        $day = (int) $found[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
