<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$root = dirname(__DIR__);
$mail = getenv('STALL_MAIL_FILE');
if (!is_string($mail) || $mail === '') {
    $directory = $root . DIRECTORY_SEPARATOR . 'var';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        fwrite(STDERR, "the log directory could not be created\n");
        exit(1);
    }
    $mail = $directory . DIRECTORY_SEPARATOR . 'check-worker-mail.txt';
    $created = fopen($mail, 'ab');
    if ($created === false) {
        fwrite(STDERR, "the mail file could not be created\n");
        exit(1);
    }
    fclose($created);
    putenv('STALL_MAIL_FILE=' . $mail);
}

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
$before = attempts($pdo);
$exit = runWorker($root);
if ($exit !== 0) {
    fwrite(STDERR, "the worker pass exited $exit\n");
    exit(1);
}

$failed = unfinished($pdo, $before);
if ($failed !== []) {
    foreach ($failed as $line) {
        fwrite(STDERR, $line . "\n");
    }
    exit(1);
}

$due = $pdo->query(
    'SELECT count(*) FROM platform.jobs WHERE finished_at IS NULL AND run_at <= clock_timestamp()'
);
$waiting = $due === false ? 1 : (int) $due->fetchColumn();
if ($waiting > 0) {
    fwrite(STDERR, "the worker left $waiting jobs due\n");
    exit(1);
}

fwrite(STDOUT, "ok worker\n");

/**
 * @return array<string, int>
 */
function attempts(PDO $pdo): array
{
    $listed = $pdo->query('SELECT id::text AS id, attempts FROM platform.jobs');
    if ($listed === false) {
        throw new RuntimeException('the job queue could not be read');
    }
    $attempts = [];
    while (($row = $listed->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row) || !is_string($row['id'] ?? null)) {
            continue;
        }
        $attempts[$row['id']] = (int) $row['attempts'];
    }

    return $attempts;
}

/**
 * @param array<string, int> $before
 * @return list<string>
 */
function unfinished(PDO $pdo, array $before): array
{
    $listed = $pdo->query(
        'SELECT id::text AS id, tenant, kind, attempts, finished_at::text AS finished_at, error
         FROM platform.jobs'
    );
    if ($listed === false) {
        return ['the job queue could not be read'];
    }
    $failed = [];
    while (($row = $listed->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row) || !is_string($row['id'] ?? null)) {
            continue;
        }
        $id = $row['id'];
        $attempts = (int) ($row['attempts'] ?? 0);
        $previous = $before[$id] ?? 0;
        if (isset($before[$id]) && $attempts <= $previous) {
            continue;
        }
        $finished = is_string($row['finished_at'] ?? null) && $row['finished_at'] !== '';
        $error = $row['error'] ?? null;
        $clean = $error === null || $error === '';
        if ($finished && $clean) {
            continue;
        }
        $tenant = is_string($row['tenant'] ?? null) ? $row['tenant'] : '';
        $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
        $detail = is_string($error) ? $error : 'unfinished';
        $failed[] = $tenant . ' ' . $kind . ' did not finish: ' . $detail;
    }

    return $failed;
}

function runWorker(string $root): int
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'work.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "the worker could not be started\n");

        return 1;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (is_string($stdout) && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if (is_string($stderr) && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    return proc_close($process);
}
