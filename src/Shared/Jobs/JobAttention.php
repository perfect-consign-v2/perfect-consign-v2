<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Scalar\RowValue;

final class JobAttention
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboundMail $mail,
        private readonly PlatformMailbox $platform,
        private readonly PlatformOperatorNotice $notices,
    ) {
    }

    public function siteReady(): bool
    {
        return $this->mail->configured();
    }

    public function gmailReady(): bool
    {
        return $this->platform->account() !== null;
    }

    /**
     * @return list<array{
     *     id: string,
     *     tenant: string,
     *     kind: string,
     *     run_at: string,
     *     finished_at: string,
     *     attempts: int,
     *     error: string,
     *     subject: string,
     *     recipients: string,
     *     transport: string,
     *     due: bool
     * }>
     */
    public function open(): array
    {
        return $this->load('open');
    }

    /**
     * @return list<array{
     *     id: string,
     *     tenant: string,
     *     kind: string,
     *     run_at: string,
     *     finished_at: string,
     *     attempts: int,
     *     error: string,
     *     subject: string,
     *     recipients: string,
     *     transport: string,
     *     due: bool
     * }>
     */
    public function givenUp(): array
    {
        return $this->load('given_up');
    }

    public function report(): void
    {
        $this->notice('overdue', 'overdue_noticed_at', 'Perfect Consign: jobs are overdue');
        $this->notice('abandoned', 'abandoned_noticed_at', 'Perfect Consign: jobs were given up');
    }

    /**
     * @param 'overdue_noticed_at'|'abandoned_noticed_at' $column
     */
    private function notice(string $bucket, string $column, string $subject): void
    {
        $rows = $this->load($bucket);
        if ($rows === []) {
            return;
        }
        if (!$this->notices->reportJobs($subject, $this->body($rows))) {
            return;
        }
        foreach ($rows as $row) {
            $this->stamp($column, $row['id']);
        }
    }

    /**
     * @param list<array{
     *     id: string,
     *     tenant: string,
     *     kind: string,
     *     run_at: string,
     *     finished_at: string,
     *     attempts: int,
     *     error: string,
     *     subject: string,
     *     recipients: string,
     *     transport: string,
     *     due: bool
     * }> $rows
     */
    private function body(array $rows): string
    {
        $blocks = [];
        foreach ($rows as $row) {
            $lines = [
                $row['tenant'] . ' ' . $row['kind'],
                'Subject: ' . $row['subject'],
                'To: ' . $row['recipients'],
                'Transport: ' . $row['transport'],
                'Error: ' . $row['error'],
                'Due: ' . $row['run_at'],
            ];
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @param 'overdue_noticed_at'|'abandoned_noticed_at' $column
     */
    private function stamp(string $column, string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            return;
        }
        $sql = match ($column) {
            'overdue_noticed_at' => 'UPDATE platform.jobs SET overdue_noticed_at = clock_timestamp() WHERE id = ?::uuid AND overdue_noticed_at IS NULL',
            'abandoned_noticed_at' => 'UPDATE platform.jobs SET abandoned_noticed_at = clock_timestamp() WHERE id = ?::uuid AND abandoned_noticed_at IS NULL',
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$id]);
    }

    /**
     * @return list<array{
     *     id: string,
     *     tenant: string,
     *     kind: string,
     *     run_at: string,
     *     finished_at: string,
     *     attempts: int,
     *     error: string,
     *     subject: string,
     *     recipients: string,
     *     transport: string,
     *     due: bool
     * }>
     */
    private function load(string $bucket): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM platform.job_attention(?)');
        $statement->execute([$bucket]);
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $this->row($row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: string,
     *     tenant: string,
     *     kind: string,
     *     run_at: string,
     *     finished_at: string,
     *     attempts: int,
     *     error: string,
     *     subject: string,
     *     recipients: string,
     *     transport: string,
     *     due: bool
     * }
     */
    private function row(array $row): array
    {
        $subject = RowValue::string($row['subject'] ?? null);
        if ($subject === '') {
            $subject = RowValue::string($row['payload_date'] ?? null);
        }
        $kind = RowValue::string($row['kind'] ?? null);
        $mailbox = RowValue::string($row['mailbox'] ?? null);
        $mailVia = RowValue::string($row['mail_via'] ?? null);

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'tenant' => RowValue::string($row['tenant'] ?? null),
            'kind' => $kind,
            'run_at' => RowValue::string($row['run_at'] ?? null),
            'finished_at' => RowValue::string($row['finished_at'] ?? null),
            'attempts' => RowValue::integer($row['attempts'] ?? 0),
            'error' => RowValue::string($row['error'] ?? null),
            'subject' => $subject,
            'recipients' => RowValue::string($row['recipients'] ?? null),
            'transport' => $this->label($kind, $mailbox, $mailVia),
            'due' => MallGuard::flag($row['due'] ?? false),
        ];
    }

    private function label(string $kind, string $mailbox, string $mailVia): string
    {
        if ($this->usesSiteSmtp($kind)) {
            return $this->siteLabel();
        }
        if ($kind === 'outbound-mail' && $mailbox === MailOutbox::MAILBOX_PLATFORM) {
            return $this->platformLabel();
        }
        if ($this->usesStoreMailbox($kind, $mailbox)) {
            return $this->storeLabel($mailVia);
        }

        return '';
    }

    private function usesSiteSmtp(string $kind): bool
    {
        return in_array($kind, ['sale-notice', 'vendor-digest', 'rent-reminder'], true);
    }

    private function usesStoreMailbox(string $kind, string $mailbox): bool
    {
        return $kind === 'vendor-letter'
            || ($kind === 'outbound-mail' && $mailbox === MailOutbox::MAILBOX_MALL);
    }

    private function platformLabel(): string
    {
        return $this->gmailReady() ? 'platform Gmail' : $this->siteLabel();
    }

    private function storeLabel(string $mailVia): string
    {
        return $mailVia === 'gmail' ? 'store Gmail' : $this->siteLabel();
    }

    private function siteLabel(): string
    {
        return $this->siteReady() ? 'site SMTP' : 'site SMTP missing';
    }
}
