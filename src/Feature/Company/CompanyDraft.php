<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Company;

final class CompanyDraft
{
    private function __construct(
        private readonly string $name,
        private readonly ?string $street,
        private readonly ?string $street2,
        private readonly ?string $city,
        private readonly ?string $state,
        private readonly ?string $postal,
        private readonly ?string $phone,
        private readonly ?string $email,
    ) {
    }

    /**
     * @param array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string} $fields
     */
    public static function parse(array $fields): self
    {
        $name = trim($fields['name']);
        if ($name === '') {
            throw new \InvalidArgumentException('Enter a store name.');
        }
        if (strlen($name) > 120) {
            throw new \InvalidArgumentException('Enter a store name of 120 characters or less.');
        }

        return new self(
            $name,
            self::optional($fields['street'], 200, 'that street is too long'),
            self::optional($fields['street2'], 200, 'that street is too long'),
            self::optional($fields['city'], 80, 'that city is too long'),
            self::optional($fields['state'], 40, 'that state is too long'),
            self::optional($fields['postal'], 20, 'that ZIP is too long'),
            self::optional($fields['phone'], 40, 'that phone is too long'),
            self::email($fields['email']),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<?string>
     */
    public function contact(): array
    {
        return [
            $this->street,
            $this->street2,
            $this->city,
            $this->state,
            $this->postal,
            $this->phone,
            $this->email,
        ];
    }

    private static function email(string $value): ?string
    {
        $email = trim($value);
        if ($email === '') {
            return null;
        }
        if (strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            throw new \InvalidArgumentException('that email is not an email address');
        }

        return $email;
    }

    private static function optional(string $value, int $limit, string $tooLong): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > $limit) {
            throw new \InvalidArgumentException($tooLong);
        }

        return $trimmed;
    }
}
