<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Http\Request;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Runs one sign-in attempt in one transaction: platform.sign_in_begin takes
 * the account and address locks and applies platform.sign_in_rules, the door
 * checks the password, and platform.sign_in_finish records the outcome. A
 * parallel burst waits on the locks, so it cannot slip past a limit. A miss
 * checks the password against a fixed hash, so it takes as long as a wrong
 * password.
 */
final class SignInGate
{
    public const LOCKED = 'Too many sign-in attempts. Try again in a few minutes.';

    private const NO_ACCOUNT_HASH = '$2y$12$2Nw9bEsvD2WcirZNcMQLzOaS1GIhn.UlOhPNAlZoG.SoCWLdUB/ii';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly SignInDevice $device,
        private readonly SignInAlert $alert,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(): SignInCheck $check
     */
    public function attempt(SignInAccount $account, string $password, callable $check): bool
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            [$verdict, $trusted, $alerted] = $this->begin($account);
            if ($verdict !== 'open') {
                if ($alerted) {
                    $this->alert->send($account);
                }
                $this->commit($own);
                $this->logger->info('sign-in locked', ['door' => $account->door, 'scope' => $verdict]);
                throw new SignInLocked(self::LOCKED);
            }
            $found = $check();
            if ($found === SignInCheck::NoAccount) {
                password_verify($password, self::NO_ACCOUNT_HASH);
            }
            $this->finish($account, $found, $trusted);
            $this->commit($own);
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if ($found !== SignInCheck::Right) {
            return false;
        }
        $this->device->send($this->deviceDays());

        return true;
    }

    /**
     * An admin password through the gate. $hash gives the stored hash, or null
     * when no admin has that email.
     *
     * @param callable(): ?string $hash
     */
    public function attemptAdmin(string $email, string $password, callable $hash): bool
    {
        return $this->attempt(
            SignInAccount::admin($email),
            $password,
            static function () use ($password, $hash): SignInCheck {
                $stored = $hash();
                if ($stored === null || $stored === '') {
                    return SignInCheck::NoAccount;
                }

                return password_verify($password, $stored) ? SignInCheck::Right : SignInCheck::Wrong;
            },
        );
    }

    public function resetAdmin(string $email): void
    {
        $this->reset(SignInAccount::admin($email));
    }

    public function resetStore(string $tenantId, string $username): void
    {
        $this->reset(SignInAccount::store($tenantId, $username));
    }

    /**
     * A password reset ends the count for the account.
     */
    public function reset(SignInAccount $account): void
    {
        $statement = $this->pdo->prepare(
            'SELECT platform.sign_in_reset(?::text, ?::text, ?::uuid, ?::text, ?::text, ?::text)'
        );
        $statement->execute([...$account->parameters(), $this->request->address, $this->request->trace->userAgent]);
    }

    /**
     * @return array{0: string, 1: bool, 2: bool}
     */
    private function begin(SignInAccount $account): array
    {
        $statement = $this->pdo->prepare(
            'SELECT verdict, trusted, alerted
             FROM platform.sign_in_begin(?::text, ?::text, ?::uuid, ?::text, ?::text, ?::text, ?::text)'
        );
        $statement->execute([
            ...$account->parameters(),
            $this->request->address,
            $this->request->trace->userAgent,
            $this->device->hash(),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['verdict'] ?? null)) {
            throw new \RuntimeException('the sign-in limits could not be read');
        }

        return [$row['verdict'], $this->flag($row['trusted'] ?? null), $this->flag($row['alerted'] ?? null)];
    }

    private function finish(SignInAccount $account, SignInCheck $found, bool $trusted): void
    {
        $outcome = match ($found) {
            SignInCheck::Right => 'ok',
            SignInCheck::Wrong => 'wrong_password',
            SignInCheck::NoAccount => 'unknown_email',
        };
        $statement = $this->pdo->prepare(
            'SELECT platform.sign_in_finish(?::text, ?::text, ?::uuid, ?::text, ?::text, ?::text, ?::text, ?::boolean, ?::text)'
        );
        $statement->execute([
            ...$account->parameters(),
            $this->request->address,
            $this->request->trace->userAgent,
            $found === SignInCheck::Right ? $this->device->claim() : null,
            $trusted ? 'true' : 'false',
            $outcome,
        ]);
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1;
    }

    private function deviceDays(): int
    {
        $days = $this->pdo->query('SELECT device_days FROM platform.sign_in_rules');
        $value = $days === false ? false : $days->fetchColumn();

        return is_numeric($value) ? (int) $value : 1;
    }

    private function commit(bool $own): void
    {
        if ($own) {
            $this->pdo->commit();
        }
    }
}
