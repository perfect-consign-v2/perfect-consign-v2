<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\OutboundMail;

final class ReceiptMailer
{
    public function __construct(
        private readonly ReceiptMailSettings $settings,
        private readonly LetterMailbox $mailbox,
        private readonly ReceiptLetter $letter,
    ) {
    }

    /**
     * @param array{
     *     sale_number: int,
     *     posted_on: string,
     *     voided: bool,
     *     lines: list<array{description: string, code: string, price: int, quantity: int, discount: int, tax?: int, store_discount?: int}>,
     *     tenders: list<array{name: string, amount: int}>,
     *     round: int,
     *     discount: int
     * } $receipt
     * @param list<string> $companyLines
     */
    public function send(array $receipt, string $to, string $storeName, array $companyLines, string $footer): void
    {
        $address = trim($to);
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $address) !== 1 || str_contains($address, "\n") || str_contains($address, "\r")) {
            throw new \InvalidArgumentException('that email is not an email address');
        }
        if (!$this->mailbox->ready()) {
            if ($this->settings->via() === 'gmail') {
                throw new \InvalidArgumentException('Gmail is not set up');
            }
            throw new \RuntimeException(OutboundMail::SMTP_NOT_CONFIGURED);
        }
        $this->mailbox->send(
            $address,
            'Receipt ' . (string) $receipt['sale_number'],
            $this->letter->body($receipt, $storeName, $companyLines, $footer),
            'receipt mail was refused',
        );
    }
}
