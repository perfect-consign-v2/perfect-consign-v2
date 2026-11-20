<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Ledger\DatabaseRefusal;

final class AuditSubject
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $kind,
        public readonly string $role,
        public readonly string $username,
        public readonly string $name,
        public readonly string $clientAddress,
        public readonly ?string $enteredByAdminId,
        public readonly ?string $enteredByEmail,
    ) {
    }

    public static function capture(Actor $actor, AdminSession $admins, string $address): self
    {
        $role = $actor->role();
        $kind = self::kind($role);
        $username = $actor->username();
        $name = $kind === 'owner' ? $username : $actor->name();
        $accountId = $actor->account();
        if (!self::complete($accountId, $kind, $role, $username, $name, $address)) {
            throw new DatabaseRefusal('the signed-in account is missing');
        }
        [$enteredByAdminId, $enteredByEmail] = self::operator($admins);

        return new self(
            $accountId,
            $kind,
            $role,
            $username,
            $name,
            $address,
            $enteredByAdminId,
            $enteredByEmail,
        );
    }

    private static function kind(string $role): string
    {
        return match ($role) {
            ActorRole::OWNER => 'owner',
            ActorRole::CASHIER, ActorRole::MANAGER, ActorRole::BOOKS, ActorRole::VENDOR => 'staff',
            default => '',
        };
    }

    private static function complete(
        string $accountId,
        string $kind,
        string $role,
        string $username,
        string $name,
        string $address,
    ): bool {
        return $accountId !== ''
            && $kind !== ''
            && $role !== ''
            && $username !== ''
            && $name !== ''
            && strlen($address) <= 64;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function operator(AdminSession $admins): array
    {
        $entered = $admins->current();
        if ($entered === null) {
            return [null, null];
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $entered['id']) !== 1 || !str_contains($entered['email'], '@')) {
            throw new DatabaseRefusal('the signed-in account is missing');
        }

        return [$entered['id'], $entered['email']];
    }
}
