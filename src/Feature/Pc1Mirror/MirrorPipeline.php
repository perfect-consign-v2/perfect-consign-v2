<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use Psr\Log\LoggerInterface;

final class MirrorPipeline
{
    public function __construct(
        private readonly string $root,
        private readonly string $sqlDirectory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function load(string $extractPath, string $targetName, string $postingDate): string
    {
        $target = MirrorTarget::open($targetName);
        $artifact = MirrorArtifact::open($extractPath);
        $pdo = $target->connection->pdo();
        (new MirrorWiper())->wipe($pdo, MirrorConstants::TARGET_SLUG);
        $mall = MirrorMall::provision($pdo, $this->sqlDirectory);
        $idMap = (new MasterLoader($this->logger))->load($mall, $artifact);
        $journalId = (new OpeningLoader())->load($mall, $artifact, $idMap, $postingDate);
        $this->plantOwner();

        return $journalId;
    }

    /**
     * @return list<string>
     */
    public function prove(string $extractPath, string $targetName): array
    {
        $target = MirrorTarget::open($targetName);
        $artifact = MirrorArtifact::open($extractPath);
        $mall = MirrorMall::openExisting($target->connection->pdo());

        return (new MirrorProver())->prove($mall, $artifact);
    }

    private function plantOwner(): void
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            $this->note('set STALL_OWNER_PASSWORD and run set-owner for ' . MirrorConstants::TARGET_SLUG);

            return;
        }
        if (PHP_SAPI !== 'cli' || str_contains(PHP_BINARY, 'php-fpm')) {
            $this->note('owner plant skipped in non-CLI; run: php bin/set-owner.php '
                . MirrorConstants::TARGET_SLUG . ' owner');

            return;
        }
        $previousEmail = getenv('STALL_OWNER_EMAIL');
        $mirrorEmail = 'owner@' . MirrorConstants::TARGET_SLUG . '.test';
        putenv('STALL_OWNER_EMAIL=' . $mirrorEmail);
        $_ENV['STALL_OWNER_EMAIL'] = $mirrorEmail;
        try {
            $script = $this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'set-owner.php';
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
                . escapeshellarg(MirrorConstants::TARGET_SLUG) . ' ' . escapeshellarg('owner');
            passthru($command, $code);
            if ($code !== 0) {
                $this->note('owner plant skipped; sign in after: php bin/set-owner.php '
                    . MirrorConstants::TARGET_SLUG . ' owner');
            }
        } finally {
            if (is_string($previousEmail) && $previousEmail !== '') {
                putenv('STALL_OWNER_EMAIL=' . $previousEmail);
                $_ENV['STALL_OWNER_EMAIL'] = $previousEmail;
            } else {
                putenv('STALL_OWNER_EMAIL');
                unset($_ENV['STALL_OWNER_EMAIL']);
            }
        }
    }

    private function note(string $message): void
    {
        if (\defined('STDOUT')) {
            fwrite(\STDOUT, $message . "\n");

            return;
        }
        echo $message . "\n";
    }
}
