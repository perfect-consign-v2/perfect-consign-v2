<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Http\Request;
use PDO;
use Psr\Log\LoggerInterface;

final class EmailSignIn
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly LoggerInterface $logger,
        private readonly SignInDirectory $directory,
        private readonly FrontDoor $door,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        $normalized = $this->directory->normalize($email);
        if ($normalized === null) {
            $this->logger->info('email sign-in refused');

            return false;
        }
        $this->refuseLocked($normalized);
        $match = $this->directory->find($normalized);
        if ($match === null) {
            $this->failed($normalized);
            $this->logger->info('email sign-in refused');

            return false;
        }
        try {
            $signedIn = $this->door->signIn($match['slug'], $match['username'], $password);
        } catch (SignInLocked $locked) {
            throw $locked;
        }
        if (!$signedIn) {
            $this->failed($normalized);
            $this->logger->info('email sign-in refused', ['slug' => $match['slug']]);

            return false;
        }
        $clear = $this->pdo->prepare('DELETE FROM platform.email_sign_in_failures WHERE email = ?');
        $clear->execute([$normalized]);

        return true;
    }

    private function failed(string $email): void
    {
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.email_sign_in_failures (email, client_address) VALUES (?, ?)'
        );
        $failed->execute([$email, $this->request->address]);
    }

    private function refuseLocked(string $email): void
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.email_sign_in_failures
             WHERE email = ?
               AND client_address = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email, $this->request->address]);
        if ((int) $recent->fetchColumn() >= 5) {
            $this->logger->info('email sign-in locked', ['email' => $email]);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
    }
}
