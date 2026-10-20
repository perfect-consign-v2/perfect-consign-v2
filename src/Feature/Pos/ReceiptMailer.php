<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\SmtpClient;

final class ReceiptMailer
{
    public function __construct(
        private readonly ReceiptMailSettings $settings,
        private readonly OutboundMail $mail,
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
        $subject = 'Receipt ' . (string) $receipt['sale_number'];
        $body = $this->letter->body($receipt, $storeName, $companyLines, $footer);
        if ($this->settings->via() === 'gmail') {
            $this->gmail($address, $subject, $body);

            return;
        }
        $this->mail->deliver($address, $subject, $body);
    }

    private function gmail(string $to, string $subject, string $body): void
    {
        $account = $this->settings->gmail();
        if ($account === null) {
            throw new \InvalidArgumentException('Gmail is not set up');
        }
        $file = getenv('STALL_MAIL_FILE');
        if (is_string($file) && $file !== '') {
            $this->mail->deliver($to, $subject, $body);

            return;
        }
        try {
            (new SmtpClient(
                $account['host'],
                $account['port'],
                $account['username'],
                $account['password'],
                $account['from'],
            ))->send($to, $subject, $body);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Gmail refused the receipt');
        }
    }
}
