<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use Psr\Log\LoggerInterface;

/**
 * The root sign-in: the email finds the store and the sign-in name, and the
 * store's door does the rest. An email that signs in nowhere goes through the
 * sign-in limits too, so it is counted and takes as long as a wrong password.
 */
final class EmailSignIn
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SignInDirectory $directory,
        private readonly FrontDoor $door,
        private readonly SignInGate $gate,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        $normalized = $this->directory->normalize($email);
        if ($normalized === null) {
            $this->logger->info('email sign-in refused');

            return false;
        }
        $match = $this->directory->find($normalized);
        if ($match === null) {
            $this->gate->attempt(
                SignInAccount::unknownEmail($normalized),
                $password,
                static fn (): SignInCheck => SignInCheck::NoAccount,
            );
            $this->logger->info('email sign-in refused');

            return false;
        }

        return $this->door->signIn($match['slug'], $match['username'], $password, $normalized);
    }
}
