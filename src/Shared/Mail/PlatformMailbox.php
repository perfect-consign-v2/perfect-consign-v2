<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

/**
 * Platform env mailbox (STALL_GMAIL_* or STALL_SMTP_* / STALL_MAIL_FILE).
 * Never the mall Settings Gmail row.
 */
final class PlatformMailbox
{
    public function __construct(
        private readonly OutboundMail $mail,
        private readonly GmailSender $gmail,
        private readonly PlatformOperatorNotice $notices,
    ) {
    }

    public function ready(): bool
    {
        return $this->mail->captured()
            || $this->mail->configured()
            || $this->account() !== null;
    }

    public function send(string $to, string $subject, string $body, string $purpose): void
    {
        $account = $this->account();
        $file = getenv('STALL_MAIL_FILE');
        try {
            if ($account === null || (is_string($file) && $file !== '')) {
                $this->mail->deliver($to, $subject, $body);
            } else {
                $this->gmail->send($account, $to, $subject, $body, $purpose);
            }
        } catch (\Throwable $error) {
            if ($account === null || (is_string($file) && $file !== '')) {
                $this->notices->mailFailed($purpose, $error);
            }
            throw $error;
        }
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, from: string}|null
     */
    public function account(): ?array
    {
        $address = getenv('STALL_GMAIL_ADDRESS');
        $password = getenv('STALL_GMAIL_APP_PASSWORD');
        if (!is_string($address) || $address === '' || !is_string($password) || $password === '') {
            return null;
        }
        $password = str_replace(' ', '', $password);
        if ($password === '') {
            return null;
        }

        return [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => $address,
            'password' => $password,
            'from' => $address,
        ];
    }
}
