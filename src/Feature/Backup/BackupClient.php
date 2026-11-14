<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class BackupClient
{
    /** @var null|\Closure(BackupConnection, string): BackupFile */
    private ?\Closure $create = null;

    /** @var null|\Closure(BackupFile): bool */
    private ?\Closure $send = null;

    /** @var null|\Closure(BackupFile): void */
    private ?\Closure $delete = null;

    public function __construct(private readonly TenantBackup $archives)
    {
    }

    /**
     * @param \Closure(BackupConnection, string): BackupFile $create
     * @param \Closure(BackupFile): bool $send
     * @param \Closure(BackupFile): void $delete
     */
    public function script(\Closure $create, \Closure $send, \Closure $delete): void
    {
        $this->create = $create;
        $this->send = $send;
        $this->delete = $delete;
    }

    public function create(BackupConnection $connection, string $slug): BackupFile
    {
        if ($this->create !== null) {
            return ($this->create)($connection, $slug);
        }

        return $this->archives->create($connection, $slug);
    }

    public function send(BackupFile $file): bool
    {
        if ($this->send !== null) {
            return ($this->send)($file);
        }

        return $this->archives->send($file);
    }

    public function delete(BackupFile $file): void
    {
        if ($this->delete !== null) {
            ($this->delete)($file);

            return;
        }
        $this->archives->delete($file);
    }
}
