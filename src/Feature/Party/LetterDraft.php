<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;

final class LetterDraft
{
    public function subject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new InvalidArgumentException('Enter a subject.');
        }
        if (str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new InvalidArgumentException('Enter a subject on one line.');
        }
        if (strlen($subject) > 150) {
            throw new InvalidArgumentException('The subject is too long.');
        }

        return $subject;
    }

    public function message(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Enter a message.');
        }
        if (strlen($message) > 4000) {
            throw new InvalidArgumentException('The message is too long.');
        }

        return $message;
    }
}
