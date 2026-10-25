<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;

class LetterMailbox
{
    public function __construct(
        private readonly ReceiptMailSettings $settings,
        private readonly OutboundMail $mail,
    ) {
    }

    public function ready(): bool
    {
        if ($this->mail->captured()) {
            return true;
        }
        if ($this->settings->via() === 'gmail') {
            return $this->settings->gmail() !== null;
        }

        return $this->mail->configured();
    }

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->settings->via() === 'gmail' && !$this->mail->captured()) {
            $this->gmail($to, $subject, $body);

            return;
        }
        $this->mail->deliver($to, $subject, $body);
    }

    private function gmail(string $to, string $subject, string $body): void
    {
        $account = $this->settings->gmail();
        if ($account === null) {
            throw new \RuntimeException('Gmail is not set up');
        }
        try {
            (new SmtpClient(
                $account['host'],
                $account['port'],
                $account['username'],
                $account['password'],
                $account['from'],
            ))->send($to, $subject, $body);
        } catch (MailDeliveryFailed $failed) {
            throw $failed;
        } catch (\Throwable) {
            throw new \RuntimeException('smtp send failed');
        }
    }
}
