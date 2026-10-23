<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Scalar\RowValue;

final class SaleNoticeJob
{
    public function __construct(
        private readonly Statements $statements,
        private readonly OutboundMail $mail,
        private readonly MailBounce $bounce,
    ) {
    }

    public function notice(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }
        $rows = $this->statements->bound(
            "SELECT vendor.email, vendor.name, item.code,
                    (sale.price_minor * sale.quantity - sale.discount_minor - sale.store_discount_minor) AS net_minor
             FROM journals AS journal
             JOIN sale_lines AS sale ON sale.journal_id = journal.id
             JOIN items AS item ON item.id = sale.item_id
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             WHERE journal.source_type = 'sale'
               AND NOT journal.is_reversal
               AND journal.posting_date = ?::date
               AND vendor.email IS NOT NULL
               AND vendor.email <> ''
               AND NOT vendor.email_bounced",
            [$date]
        );
        /** @var array<string, array{name: string, lines: list<string>}> $letters */
        $letters = [];
        foreach ($rows as $row) {
            $email = RowValue::optionalString($row['email'] ?? null);
            if ($email === null) {
                continue;
            }
            if (!isset($letters[$email])) {
                $letters[$email] = ['name' => RowValue::string($row['name'] ?? null), 'lines' => []];
            }
            $letters[$email]['lines'][] = RowValue::string($row['code'] ?? null) . ' ' . RowValue::integer($row['net_minor'] ?? null);
        }
        foreach ($letters as $email => $letter) {
            $name = $letter['name'];
            $lines = $letter['lines'];
            try {
                $this->mail->deliver($email, 'Sold today', $name . "\n" . implode("\n", $lines));
            } catch (\Throwable) {
                $this->bounce->mark($email);
            }
        }
    }
}
