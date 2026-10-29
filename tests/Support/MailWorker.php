<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PerfectApp\Logger\FileLogger;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\DigestJob;
use PerfectConsign\Shared\Jobs\JobDispatch;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PerfectConsign\Shared\Jobs\LateFeeJob;
use PerfectConsign\Shared\Jobs\MarkdownJob;
use PerfectConsign\Shared\Jobs\OutboundMailJob;
use PerfectConsign\Shared\Jobs\ReconcileJob;
use PerfectConsign\Shared\Jobs\SaleNoticeJob;
use PerfectConsign\Shared\Jobs\VendorLetterJob;
use PerfectConsign\Shared\Jobs\WorkerRuns;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;
use RuntimeException;

final class MailWorker
{
    public static function flush(): void
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $password = getenv('STALL_WORKER_PASSWORD');
        if (!is_string($host) || $host === '' || !is_string($port) || $port === '' || !is_string($name) || $name === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, and STALL_WORKER_PASSWORD are required');
        }
        $previousMail = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . ProbeServer::mailFile());
        try {
            $pdo = new PDO(
                'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
                'stall_worker',
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $root = dirname(__DIR__, 2);
            $logDirectory = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
            if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
                throw new RuntimeException('the log directory could not be created');
            }
            $logger = new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'app.log');
            $queue = new JobQueue();
            $mail = new OutboundMail($logger);
            $notices = new PlatformOperatorNotice($logger);
            $gmail = new GmailSender($notices);
            $statements = new Statements($pdo);
            $bounce = new MailBounce($statements);
            $mailbox = new LetterMailbox(new ReceiptMailSettings($statements), $mail, $gmail);
            $runner = new JobRunner(
                $pdo,
                $queue,
                $logger,
                new JobSchedule($pdo, $queue),
                new JobDispatch(
                    new LateFeeJob($pdo),
                    new DigestJob($pdo, $mail, $bounce, $logger, new MailProgress()),
                    new ReconcileJob($pdo),
                    new SaleNoticeJob($statements, $mail, $bounce, $logger, new MailProgress()),
                    new VendorLetterJob($statements, $mailbox, $bounce, $logger),
                    new MarkdownJob($pdo),
                    new OutboundMailJob(
                        new MailOutbox($pdo),
                        $mailbox,
                        new PlatformMailbox($mail, $gmail, $notices),
                    ),
                ),
                new WorkerRuns($pdo),
            );
            $runner->pass('test-mail');
        } finally {
            if (is_string($previousMail) && $previousMail !== '') {
                putenv('STALL_MAIL_FILE=' . $previousMail);
            } else {
                putenv('STALL_MAIL_FILE');
            }
        }
    }
}
