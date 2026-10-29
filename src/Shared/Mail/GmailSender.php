<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

final class GmailSender
{
    public function __construct(private readonly PlatformOperatorNotice $notices)
    {
    }

    /**
     * @param array{host: string, port: int, username: string, password: string, from: string} $account
     */
    public function send(array $account, string $to, string $subject, string $body, string $purpose): void
    {
        try {
            (new SmtpClient(
                $account['host'],
                $account['port'],
                $account['username'],
                $account['password'],
                $account['from'],
            ))->send($to, $subject, $body);
        } catch (MailDeliveryFailed $failed) {
            $this->notices->mailFailed($purpose, $failed, [
                'from' => $account['from'],
            ]);
            throw $failed;
        } catch (\Throwable $error) {
            $wrapped = new \RuntimeException('smtp send failed', 0, $error);
            $this->notices->mailFailed($purpose, $wrapped, [
                'from' => $account['from'],
            ]);
            throw $wrapped;
        }
    }
}
