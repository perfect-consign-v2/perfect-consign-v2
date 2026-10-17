<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class FrontDoor
{
    public function __construct(
        private readonly MissingPage $missing,
        private readonly PriorClose $prior,
        private readonly OwnerGate $owner,
        private readonly PostedRefusal $refusals,
        private readonly Actor $actor,
        private readonly CredentialStamp $credentials,
    ) {
    }

    public function credentialsMatch(TenantRecord $tenant): bool
    {
        return $this->credentials->matches($tenant);
    }

    public function miss(string $reason): never
    {
        $this->missing->render($reason, $this->owner->token());
    }

    /**
     * @param list<array{id: string, name: string, opened: string}> $leftOpen
     */
    public function priorShift(string $slug, array $leftOpen): ?string
    {
        return $this->prior->redirect($slug, $leftOpen);
    }

    public function signIn(string $slug, string $username, string $password): bool
    {
        return $this->owner->signIn($slug, $username, $password);
    }

    public function signOut(): void
    {
        $this->owner->signOut();
    }

    public function checkCsrf(): void
    {
        $this->owner->checkCsrf();
    }

    public function token(): string
    {
        return $this->owner->token();
    }

    public function catchRefusal(\Throwable $error): string
    {
        return $this->refusals->phrase($error);
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    public function go(string $path): never
    {
        header('Location: ' . $path, true, 303);
        throw new ResponseSent('redirect');
    }
}
