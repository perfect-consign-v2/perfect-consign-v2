<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Mail\MailAttempt;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Scalar\RowValue;
use Psr\Log\LoggerInterface;

final class SaleNoticeJob
{
    public function __construct(
        private readonly Statements $statements,
        private readonly OutboundMail $mail,
        private readonly MailBounce $bounce,
        private readonly LoggerInterface $logger,
        private readonly MailProgress $progress,
    ) {
    }

    public function notice(string $tenant, string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }
        unset($tenant);
        $this->deliver($date, $this->letters($date));
    }

    /**
     * @return array<string, array{name: string, lines: list<string>}>
     */
    private function letters(string $date): array
    {
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
            $letters[$email]['lines'][] = RowValue::string($row['code'] ?? null) . ' ' . Money::format(RowValue::integer($row['net_minor'] ?? null));
        }

        return $letters;
    }

    /**
     * @param array<string, array{name: string, lines: list<string>}> $letters
     */
    private function deliver(string $date, array $letters): void
    {
        $waiting = false;
        foreach ($letters as $email => $letter) {
            if (!is_string($email) || $this->progress->sent($this->statements, 'sale-notice', $date, $email)) {
                continue;
            }
            try {
                MailAttempt::send(
                    $this->mail,
                    $this->bounce,
                    $this->logger,
                    $email,
                    'Sold today',
                    $letter['name'] . "\n" . implode("\n", $letter['lines'])
                );
            } catch (\RuntimeException $failed) {
                $this->logger->error('a letter is still waiting', ['email' => $email, 'error' => $failed->getMessage()]);
                $waiting = true;
                continue;
            }
            $this->progress->record($this->statements, 'sale-notice', $date, $email);
        }
        if ($waiting) {
            throw new \RuntimeException('a letter is still waiting to be sent');
        }
    }
}
