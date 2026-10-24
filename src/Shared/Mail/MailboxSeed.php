<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PDO;
use RuntimeException;

final class MailboxSeed
{
    public static function plant(PDO $pdo): void
    {
        $address = getenv('STALL_GMAIL_ADDRESS');
        $password = getenv('STALL_GMAIL_APP_PASSWORD');
        if (!is_string($address) || $address === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('STALL_GMAIL_ADDRESS and STALL_GMAIL_APP_PASSWORD are required');
        }
        $statement = $pdo->prepare(
            'UPDATE mall SET receipt_mail_via = ?, gmail_address = ?, gmail_app_password = ?'
        );
        $statement->execute(['gmail', $address, SealedSecret::seal($password)]);
    }
}
