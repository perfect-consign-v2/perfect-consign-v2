<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/load-env.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectApp\Logger\FileLogger;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\DailyBackupFiles;
use PerfectConsign\Feature\Backup\TenantBackup;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\PostgresTools;

$slug = $argv[1] ?? '';
if (!is_string($slug) || $slug === '') {
    fwrite(STDERR, "usage: php bin/backup-tenant.php <slug>\n");
    exit(1);
}
$root = getenv('STALL_BACKUP_DIR');
if (!is_string($root) || $root === '') {
    fwrite(STDERR, "STALL_BACKUP_DIR is required\n");
    exit(1);
}

$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
    fwrite(STDERR, "The log directory could not be created.\n");
    exit(1);
}
$logger = new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'app.log');
$archives = new TenantBackup($logger, new PostgresTools(new CommandProcess($logger)));
$archive = null;
try {
    $archive = $archives->create(BackupConnection::stallBackup(), $slug);
    $pdo = BackupConnection::stallBackup()->pdo();
    $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
    $statement->execute([$slug]);
    $schema = $statement->fetchColumn();
    if (!is_string($schema)) {
        throw new BackupException('That mall has no backup.');
    }
    $files = new DailyBackupFiles($root);
    $files->replace($schema, substr($archive->takenAt, 0, 10), $archive->path);
    $files->removeOlderThan(new DateTimeImmutable('now', new DateTimeZone('UTC')));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($archive !== null) {
        $archives->delete($archive);
    }
}

fwrite(STDOUT, $slug . "\n");
