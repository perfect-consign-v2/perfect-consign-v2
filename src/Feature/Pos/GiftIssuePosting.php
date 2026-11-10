<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class GiftIssuePosting
{
    /** @var list<string> */
    private const TENDERS = ['cash', 'card', 'checks'];

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
    ) {
    }

    public function issue(
        string $session,
        string $date,
        string $number,
        int $amount,
        string $tender,
        ?string $check,
        string $kind = 'gift_certificate',
        string $formToken = '',
    ): string {
        $kind = GiftKinds::normalize($kind);
        $requested = $this->number($number);
        $this->tender($amount, $tender, $check);
        $tokenKey = $this->tokenKey($kind, $formToken);
        $existing = $this->issuedForToken($requested, $tokenKey);
        if ($existing !== null) {
            return $existing;
        }

        return $this->issueAttempts($session, $date, $requested, $amount, $tender, $check, $kind, $tokenKey);
    }

    private function tokenKey(string $kind, string $formToken): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $formToken) !== 1) {
            throw new DatabaseRefusal('this form has expired');
        }

        return str_replace('_', '-', $kind) . ':token:' . $formToken;
    }

    private function issuedForToken(string $requested, string $tokenKey): ?string
    {
        if ($requested !== '') {
            return null;
        }
        $existing = $this->statements->bound(
            'SELECT id::text AS id FROM journals WHERE posting_key = ?',
            [$tokenKey]
        );
        if ($existing === []) {
            return null;
        }

        return RowValue::string($existing[0]['id'] ?? null);
    }

    private function issueAttempts(
        string $session,
        string $date,
        string $requested,
        int $amount,
        string $tender,
        ?string $check,
        string $kind,
        string $tokenKey,
    ): string {
        $refusal = new DatabaseRefusal(DatabaseRefusal::CERTIFICATE_NUMBER_USED);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return $this->issueOnce(
                    $session,
                    $date,
                    $requested !== '' ? $requested : $this->allocate($kind),
                    $amount,
                    $tender,
                    $check,
                    $kind,
                    $requested === '' ? $tokenKey : null,
                );
            } catch (DatabaseRefusal $error) {
                $refusal = $error;
                if ($requested !== '' || !DatabaseRefusal::isCertificateNumberUsed($error)) {
                    throw $error;
                }
            }
        }

        throw $refusal;
    }

    private function issueOnce(
        string $session,
        string $date,
        string $assigned,
        int $amount,
        string $tender,
        ?string $check,
        string $kind,
        ?string $postingKey,
    ): string {
        $journalId = '';
        $this->statements->isolated(function () use (
            $session,
            $date,
            $assigned,
            $amount,
            $tender,
            $check,
            $kind,
            $postingKey,
            &$journalId,
        ): void {
            $journalId = $this->store(
                $session,
                $date,
                $assigned,
                $amount,
                $tender,
                $check,
                $kind,
                $postingKey,
            );
        });
        if ($journalId === '') {
            throw new DatabaseRefusal('that journal is not a gift certificate');
        }

        return $journalId;
    }

    private function store(
        string $session,
        string $date,
        string $number,
        int $amount,
        string $tender,
        ?string $check,
        string $kind,
        ?string $postingKey = null,
    ): string {
        $created = $this->statements->bound(
            'INSERT INTO stored_values (kind, number) VALUES (?, ?) RETURNING id',
            [$kind, $number]
        );
        $storedId = RowValue::string($created[0]['id'] ?? null);
        $key = $postingKey ?? (str_replace('_', '-', $kind) . ':' . strtolower($number));
        try {
            return $this->ledger->post(new JournalDraft(
                $key,
                $date,
                new JournalSource($kind . '_issue', $number, GiftKinds::label($kind)),
                [
                    PostedLines::entry($this->ledger->roleAccount($tender), $amount, 0, null, null),
                    PostedLines::entry($this->ledger->roleAccount($kind), 0, $amount, $kind, $storedId),
                ],
                sessionId: $session,
                checkNumber: $tender === 'checks' ? $check : null,
            ));
        } catch (\Throwable $error) {
            $this->statements->change(
                'DELETE FROM stored_values WHERE id = ?::uuid
                 AND NOT EXISTS (SELECT 1 FROM journal_lines WHERE subledger_ref = ?::uuid)',
                [$storedId, $storedId]
            );
            throw $error;
        }
    }

    private function allocate(string $kind): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bytes = random_bytes(10);
        $body = '';
        for ($i = 0; $i < 10; $i++) {
            $body .= $alphabet[ord($bytes[$i]) % 32];
        }

        return GiftKinds::prefix($kind) . '-' . $body;
    }

    private function number(string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }
        if (strlen($number) > 40) {
            throw new DatabaseRefusal('a stored value number is required');
        }

        return $number;
    }

    private function tender(int $amount, string $tender, ?string $check): void
    {
        if ($amount < 1 || !in_array($tender, self::TENDERS, true)) {
            throw new DatabaseRefusal('stored value is issued for cash, card, or a check');
        }
        if ($tender === 'checks' && ($check === null || $check === '')) {
            throw new DatabaseRefusal(GiftIssue::CHECK_REQUIRED);
        }
    }
}
