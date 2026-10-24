<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\SealedSecret;
use PerfectConsign\Shared\Scalar\RowValue;

final class ReceiptMailSettings
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function via(): string
    {
        $via = RowValue::string($this->row()['receipt_mail_via'] ?? null);

        return $via === 'gmail' ? 'gmail' : 'server';
    }

    public function gmailAddress(): string
    {
        $address = $this->row()['gmail_address'] ?? null;

        return is_string($address) ? $address : '';
    }

    public function hasGmailPassword(): bool
    {
        $password = $this->row()['gmail_app_password'] ?? null;

        return is_string($password) && $password !== '';
    }

    public function save(string $via, string $address, string $password): void
    {
        $chosen = ReceiptMailDraft::via($via);
        $gmail = ReceiptMailDraft::address($address);
        $secret = ReceiptMailDraft::password($password);
        if ($secret === null && $this->hasGmailPassword()) {
            $secret = $this->storedPassword();
        }
        if ($chosen === 'gmail' && ($gmail === null || $secret === null)) {
            throw new \InvalidArgumentException('Gmail needs an address and an app password');
        }
        if ($secret === null) {
            $this->statements->change(
                'UPDATE mall SET receipt_mail_via = ?, gmail_address = ?, gmail_app_password = NULL',
                [$chosen, $gmail]
            );

            return;
        }
        $this->statements->change(
            'UPDATE mall SET receipt_mail_via = ?, gmail_address = ?, gmail_app_password = ?',
            [$chosen, $gmail, SealedSecret::seal($secret)]
        );
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, from: string}|null
     */
    public function gmail(): ?array
    {
        if ($this->via() !== 'gmail' || !$this->hasGmailPassword()) {
            return null;
        }
        $address = $this->gmailAddress();
        if ($address === '') {
            return null;
        }

        return [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => $address,
            'password' => $this->storedPassword(),
            'from' => $address,
        ];
    }

    private function storedPassword(): string
    {
        $password = $this->row()['gmail_app_password'] ?? null;

        return is_string($password) ? SealedSecret::open($password) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->statements->rows(
            'SELECT receipt_mail_via, gmail_address, gmail_app_password FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return $row;
    }
}
