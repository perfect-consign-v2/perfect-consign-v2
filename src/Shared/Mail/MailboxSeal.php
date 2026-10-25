<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PDO;
use RuntimeException;

final class MailboxSeal
{
    public static function sealPlain(PDO $pdo): void
    {
        $listed = $pdo->query(
            "SELECT gmail_app_password FROM mall
             WHERE gmail_app_password IS NOT NULL
               AND NOT (
                   gmail_app_password ~ '^v1:[A-Za-z0-9+/]+=*$'
                   AND char_length(gmail_app_password) BETWEEN 20 AND 200
               )"
        );
        if ($listed === false) {
            throw new RuntimeException('the mailbox password could not be sealed');
        }
        $passwords = $listed->fetchAll(PDO::FETCH_COLUMN);
        $update = $pdo->prepare('UPDATE mall SET gmail_app_password = ? WHERE gmail_app_password = ?');
        if ($update === false) {
            throw new RuntimeException('the mailbox password could not be sealed');
        }
        foreach ($passwords as $password) {
            if (!is_string($password) || $password === '') {
                continue;
            }
            $update->execute([SealedSecret::seal($password), $password]);
        }
    }
}
