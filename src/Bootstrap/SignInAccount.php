<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

/**
 * The account one sign-in attempt is about. platform.sign_in_account names
 * it: an admin by email, a store account by its store and sign-in name, and an
 * email that signs in nowhere by that email.
 */
final class SignInAccount
{
    private function __construct(
        public readonly string $door,
        public readonly ?string $email,
        public readonly ?string $tenantId,
        public readonly ?string $username,
    ) {
    }

    public static function admin(string $email): self
    {
        return new self('admin', strtolower(trim($email)), null, null);
    }

    public static function store(string $tenantId, string $username, ?string $emailTried = null): self
    {
        return new self('store', $emailTried === null ? null : strtolower(trim($emailTried)), $tenantId, $username);
    }

    public static function unknownEmail(string $email): self
    {
        return new self('store', strtolower(trim($email)), null, null);
    }

    /**
     * @return list<string|null>
     */
    public function parameters(): array
    {
        return [$this->door, $this->email, $this->tenantId, $this->username];
    }
}
