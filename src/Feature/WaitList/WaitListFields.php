<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\WaitList;

final class WaitListFields
{
    /**
     * @return array{first: string, last: string, email: string, phone: string, width: int|null, depth: int|null, notes: string|null}
     */
    public function create(
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $width,
        string $depth,
        string $notes,
    ): array {
        $size = $this->size($width, $depth);

        return [
            'first' => $this->personName($firstName, 'first'),
            'last' => $this->personName($lastName, 'last'),
            'email' => $this->email($email),
            'phone' => $this->phone($phone),
            'width' => $size['width'],
            'depth' => $size['depth'],
            'notes' => $this->notes($notes),
        ];
    }

    /**
     * @return array{first: string, last: string, email: string, phone: string, width: int|null, depth: int|null, status: string, notes: string|null}
     */
    public function update(
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $width,
        string $depth,
        string $status,
        string $notes,
    ): array {
        $fields = $this->create($firstName, $lastName, $email, $phone, $width, $depth, $notes);

        return [
            'first' => $fields['first'],
            'last' => $fields['last'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
            'width' => $fields['width'],
            'depth' => $fields['depth'],
            'status' => $this->status($status),
            'notes' => $fields['notes'],
        ];
    }

    private function personName(string $value, string $part): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('a ' . $part . ' name is required');
        }

        return $trimmed;
    }

    private function email(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('an email is required');
        }
        if (strlen($trimmed) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException('that email is not an email address');
        }

        return $trimmed;
    }

    private function phone(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('a phone number is required');
        }
        if (strlen($trimmed) > 40) {
            throw new \InvalidArgumentException('that phone is too long');
        }

        return $trimmed;
    }

    private function notes(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > 2000) {
            throw new \InvalidArgumentException('those notes are too long');
        }

        return $trimmed;
    }

    /**
     * @return array{width: int|null, depth: int|null}
     */
    private function size(string $width, string $depth): array
    {
        $across = $this->feet($width);
        $back = $this->feet($depth);
        if (($across === null) !== ($back === null)) {
            throw new \InvalidArgumentException('preferred size is whole feet');
        }

        return ['width' => $across, 'depth' => $back];
    }

    private function feet(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,3}$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException('preferred size is whole feet');
        }

        return (int) $trimmed;
    }

    private function status(string $value): string
    {
        $status = trim($value);
        if (!in_array($status, ['waiting', 'contacted', 'placed', 'declined'], true)) {
            throw new \InvalidArgumentException('that status is not on the wait list');
        }

        return $status;
    }
}
