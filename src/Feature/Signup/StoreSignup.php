<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PDO;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Shared\Scalar\RowValue;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Public store signup. A request writes one pending row through
 * platform.signup_request, which holds every rule. Nothing is built until the
 * owner opens the emailed link; the worker builds the store after that.
 */
final class StoreSignup
{
    public const SENT = 'Check your email for a link to finish setting up your store.';

    public function __construct(
        private readonly PDO $pdo,
        private readonly SignupMail $mail,
        private readonly AdminAudit $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{seed: string, bits: int}
     */
    public function challenge(string $clientAddress): array
    {
        $statement = $this->pdo->prepare('SELECT seed, bits FROM platform.signup_challenge(?)');
        $statement->execute([$clientAddress]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['seed'] ?? null)) {
            throw new RuntimeException('the signup challenge was not issued');
        }

        return ['seed' => $row['seed'], 'bits' => RowValue::integer($row['bits'] ?? null)];
    }

    /**
     * Returns false when public signup is off. Throws with the sentence to show
     * when the request is refused. Otherwise the visitor sees StoreSignup::SENT,
     * whether the email is new, already has a store, or was asked too often.
     */
    public function request(
        string $storeName,
        string $email,
        string $password,
        string $confirm,
        string $clientAddress,
        string $seed,
        string $nonce,
        string $origin,
    ): bool {
        [$storeName, $email] = SignupInput::checked($storeName, $email, $password, $confirm);
        if (!$this->mail->ready()) {
            $this->logger->error('signup mailbox is not set up');
            throw new RuntimeException('Store signup cannot send email right now. Try again later.');
        }
        $link = SignupToken::issue();
        $statement = $this->pdo->prepare('SELECT platform.signup_request(?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $email,
            $storeName,
            password_hash($password, PASSWORD_DEFAULT),
            $link['hash'],
            $clientAddress,
            $seed,
            $nonce,
        ]);
        $outcome = $statement->fetchColumn();
        match ($outcome) {
            'closed' => null,
            'challenge' => throw new RuntimeException('Your browser did not finish its check. Try again.'),
            'limited' => throw new RuntimeException('Too many signup attempts. Try again later.'),
            'busy' => throw new RuntimeException('Signups are busy right now. Try again in an hour.'),
            'pending' => $this->mail->link($email, $origin, $link['token'], $this->pendingHours()),
            'known' => $this->mail->known($email, $origin),
            'quiet' => null,
            default => throw new RuntimeException('That store could not be created.'),
        };

        return $outcome !== 'closed';
    }

    /**
     * @return array{status: string, email: string, slug: string}|null
     */
    public function state(string $token): ?array
    {
        if (!SignupToken::shaped($token)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT status, email, slug FROM platform.signup_state(?)');
        $statement->execute([SignupToken::hash($token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['status'] ?? null) || !is_string($row['email'] ?? null)) {
            return null;
        }

        return [
            'status' => $row['status'],
            'email' => $row['email'],
            'slug' => is_string($row['slug'] ?? null) ? $row['slug'] : '',
        ];
    }

    /**
     * The owner pressed the button on the emailed link. Returns the signup's
     * status afterwards, or null when the link is unknown.
     */
    public function verify(string $token): ?string
    {
        if (!SignupToken::shaped($token)) {
            return null;
        }
        $before = $this->state($token);
        $statement = $this->pdo->prepare('SELECT platform.signup_verify(?)');
        $statement->execute([SignupToken::hash($token)]);
        $status = $statement->fetchColumn();
        if (!is_string($status)) {
            return null;
        }
        if ($before !== null && $before['status'] === 'pending' && $status === 'verified') {
            $this->audit->record(null, $before['email'], 'store.email_verified', 'ok');
        }

        return $status;
    }

    public function pendingHours(): int
    {
        $statement = $this->pdo->query('SELECT pending_hours FROM platform.signup_rules');
        if ($statement === false) {
            throw new RuntimeException('signup rules are missing');
        }

        return RowValue::integer($statement->fetchColumn());
    }
}
