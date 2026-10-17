<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Session\Session;
use PerfectConsign\Ledger\DatabaseRefusal;

final class SaleHold
{
    public function __construct(private readonly Session $session)
    {
    }

    public function id(string $slug): string
    {
        $held = $this->session->get('sale:' . $slug);

        return is_string($held) ? $held : '';
    }

    public function tendered(string $slug): string
    {
        $tendered = $this->session->get('sale_tendered:' . $slug);

        return is_string($tendered) ? $tendered : '';
    }

    public function last(string $slug): string
    {
        $last = $this->session->get('last_sale:' . $slug);
        if (!is_string($last) || preg_match('/^[0-9a-fA-F-]{36}$/', $last) !== 1) {
            return '';
        }

        return $last;
    }

    public function keep(string $slug, string $journalId, string $tendered): void
    {
        $this->session->set('sale:' . $slug, $journalId);
        $this->session->set('sale_tendered:' . $slug, $tendered);
        $this->session->set('last_sale:' . $slug, $journalId);
    }

    public function clear(string $slug): void
    {
        $held = $this->id($slug);
        if ($held !== '') {
            $this->session->set('last_sale:' . $slug, $held);
        }
        $this->session->delete('sale:' . $slug);
        $this->session->delete('sale_tendered:' . $slug);
    }

    public function refuse(string $slug): void
    {
        if ($this->id($slug) !== '') {
            throw new DatabaseRefusal('start a new sale');
        }
    }
}
