<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDOException;
use RuntimeException;

final class DatabaseRefusal extends RuntimeException
{
    public static function fromPdo(PDOException $error): self
    {
        $message = $error->getMessage();
        if (preg_match('/ERROR:\s+(.+)/s', $message, $matches) === 1) {
            $text = preg_split('/\r?\n|DETAIL:|CONTEXT:/', $matches[1]) ?: [];
            $first = trim((string) ($text[0] ?? ''));
            if ($first !== '') {
                return new self($first, 0, $error);
            }
        }

        return new self('the database refused the change', 0, $error);
    }
}
