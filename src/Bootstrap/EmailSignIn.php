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
        } catch (SignInLocked | SignInUnverified $locked) {
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
        $this->pruneStale($email);
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.email_sign_in_failures (email, client_address) VALUES (?, ?)'
        );
        $failed->execute([$email, $this->request->address]);
    }

    private function pruneStale(string $email): void
    {
        $forEmail = $this->pdo->prepare(
            "DELETE FROM platform.email_sign_in_failures
             WHERE email = ?
               AND failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $forEmail->execute([$email]);
        $stale = $this->pdo->prepare(
            "DELETE FROM platform.email_sign_in_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $stale->execute();
    }

    private function refuseLocked(string $email): void
    {
        if ($this->recentForPair($email) >= 5) {
            $this->logger->info('email sign-in locked', ['email' => $email, 'scope' => 'pair']);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
        if ($this->recentForClient() >= 40) {
            $this->logger->info('email sign-in locked', ['email' => $email, 'scope' => 'client']);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
        if ($this->recentForEmail($email) >= 20) {
            $this->logger->info('email sign-in locked', ['email' => $email, 'scope' => 'email']);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
    }

    private function recentForPair(string $email): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.email_sign_in_failures
             WHERE email = ?
               AND client_address = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email, $this->request->address]);

        return (int) $recent->fetchColumn();
    }

    private function recentForClient(): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.email_sign_in_failures
             WHERE client_address = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$this->request->address]);

        return (int) $recent->fetchColumn();
    }

    private function recentForEmail(string $email): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.email_sign_in_failures
             WHERE email = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email]);

        return (int) $recent->fetchColumn();
    }
}
