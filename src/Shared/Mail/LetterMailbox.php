<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;

class LetterMailbox
{
    public function __construct(
        private readonly ReceiptMailSettings $settings,
        private readonly OutboundMail $mail,
        private readonly GmailSender $gmail,
    ) {
    }

    public function ready(): bool
    {
        if ($this->settings->via() === 'gmail') {
            return $this->settings->gmail() !== null;
        }
        if ($this->mail->captured()) {
            return true;
        }

        return $this->mail->configured();
    }

    public function send(
        string $to,
        string $subject,
        string $body,
        string $purpose = 'vendor letter mail was refused',
    ): void {
        if ($this->settings->via() === 'gmail' && !$this->mail->captured()) {
            $this->gmail($to, $subject, $body, $purpose);

            return;
        }
        $this->mail->deliver($to, $subject, $body);
    }

    private function gmail(string $to, string $subject, string $body, string $purpose): void
    {
        $account = $this->settings->gmail();
        if ($account === null) {
            throw new \RuntimeException('Gmail is not set up');
        }
        $this->gmail->send($account, $to, $subject, $body, $purpose);
    }
}
