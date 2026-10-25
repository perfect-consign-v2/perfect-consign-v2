<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectApp\Logger\FileLogger;
use PerfectConsign\Shared\Jobs\DigestJob;
use PerfectConsign\Shared\Jobs\JobDispatch;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PerfectConsign\Shared\Jobs\LateFeeJob;
use PerfectConsign\Shared\Jobs\MarkdownJob;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\ReconcileJob;
use PerfectConsign\Shared\Jobs\SaleNoticeJob;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Jobs\VendorLetterJob;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;

$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$password = getenv('STALL_WORKER_PASSWORD');
if (!is_string($host) || $host === '' || !is_string($port) || $port === '' || !is_string($name) || $name === '' || !is_string($password) || $password === '') {
    fwrite(STDERR, "STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, and STALL_WORKER_PASSWORD are required\n");
    exit(1);
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    'stall_worker',
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
    fwrite(STDERR, "the log directory could not be created\n");
    exit(1);
}
$logger = new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'app.log');
$queue = new JobQueue();
$mail = new OutboundMail($logger);
$bounce = new MailBounce(new Statements($pdo));
$runner = new JobRunner(
    $pdo,
    $queue,
    $logger,
    new JobSchedule($pdo, $queue),
    new JobDispatch(
        new LateFeeJob($pdo),
        new DigestJob($pdo, $mail, $bounce, $logger, new MailProgress()),
        new ReconcileJob($pdo),
        new SaleNoticeJob(new Statements($pdo), $mail, $bounce, $logger, new MailProgress()),
        new VendorLetterJob(
            new Statements($pdo),
            new LetterMailbox(new ReceiptMailSettings(new Statements($pdo)), $mail),
            $bounce,
            $logger,
        ),
        new MarkdownJob($pdo),
    ),
);
$forever = in_array('--forever', $argv, true);
do {
    $runner->pass('work');
    if ($forever) {
        sleep(30);
    }
} while ($forever);
