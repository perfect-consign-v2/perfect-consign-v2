<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

/**
 * What a store password check found: the verdict and the owner or staff row
 * the session opens with.
 */
final class StoreAccountMatch
{
    /**
     * @param array{id: string, hash: string, version: string, verified: bool, can_sign_in: bool}|null $owner
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool}|null $staff
     */
    public function __construct(
        public readonly SignInCheck $check,
        public readonly ?array $owner,
        public readonly ?array $staff,
    ) {
    }
}
