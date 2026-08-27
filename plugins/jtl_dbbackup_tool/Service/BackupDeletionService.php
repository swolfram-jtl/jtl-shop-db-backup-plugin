<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Löschen: nur lokal": deletes the local backup file + its
 * .manifest.json + the history row — a remote FTP/SFTP copy (if any) is
 * deliberately left untouched. FTP/SFTP is the offsite safety copy; a
 * single delete click must never be able to wipe out both copies at once
 * (see spec "absolut zuverlässig").
 * Spec decision "Löschen blockiert während Lock": refuses to delete while a
 * backup/restore is running, same reasoning as everywhere else this lock is
 * checked — a delete racing a running backup/restore could remove a file a
 * concurrent operation still expects to exist.
 * Manual delete deliberately ignores the retention policy's `minKeep` floor
 * (BackupHistoryRepository::findExpired()) — that floor exists to protect
 * against the AUTOMATIC cron cleanup being overly aggressive, not to second-
 * guess an explicit admin action.
 */
final class BackupDeletionService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly BackupHistoryRepository $history,
        private readonly LockService $lock,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws \RuntimeException if locked or the backup doesn't exist
     */
    public function delete(int $id, string $instanceId, int $adminAccountId): void
    {
        if ($this->lock->isLocked()) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'Löschen ist momentan gesperrt, da ein Backup oder Restore läuft.',
            ));
        }

        $row = $this->history->find($id);
        if ($row === null) {
            throw new \RuntimeException(\d__('jtl_dbbackup_tool', 'Backup nicht gefunden.'));
        }

        $dir = $this->storage->backupDirFor($instanceId);
        $this->storage->delete($dir . '/' . $row->cFilename);
        $this->storage->delete($dir . '/' . $row->cFilename . '.manifest.json');
        $this->history->delete($id);

        $this->auditLogger->log($adminAccountId, 'delete', $row->cPresetKey, $row->cFilename);
    }

    /**
     * Best-effort bulk delete: one failure (e.g. a row already removed by
     * another admin) must not abort the rest of the selection.
     *
     * @param int[] $ids
     * @return array{deleted: int[], failed: array<int, string>} failed is id => error message
     */
    public function deleteMany(array $ids, string $instanceId, int $adminAccountId): array
    {
        $deleted = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $this->delete($id, $instanceId, $adminAccountId);
                $deleted[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
