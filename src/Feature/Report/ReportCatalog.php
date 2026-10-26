<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class ReportCatalog
{
    /**
     * @var list<string>
     */
    public const SOURCES = ['sales', 'rent', 'payouts', 'stored_value'];

    /**
     * @var list<string>
     */
    public const GROUPS = ['day', 'tender', 'vendor', 'cashier', 'hour', 'category', 'none'];

    public function accept(string $name, string $source, string $grouped): void
    {
        $trimmed = trim($name);
        if ($trimmed === '' || strlen($trimmed) > 80 || strtolower($trimmed) === 'sales tax') {
            throw new \InvalidArgumentException('that report name is reserved or empty');
        }
        if (!in_array($source, self::SOURCES, true) || !in_array($grouped, self::GROUPS, true)) {
            throw new \InvalidArgumentException('that report is not in the catalog');
        }
        if ($source !== 'sales' && $grouped !== 'none') {
            throw new \InvalidArgumentException('that report is not in the catalog');
        }
    }
}
