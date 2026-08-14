<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Retention-Policy": configurable combo rule — max. count AND
 * max. age, oldest deleted first, with a minimum-keep floor so an aggressive
 * setting (e.g. "max age 1 day") can never wipe out every single backup and
 * leave the admin with no fallback at all.
 */
final class RetentionService
{
    private const MIN_KEEP = 3;

    public function __construct(
        private readonly BackupHistoryRepository $history,
        private readonly StorageService $storage,
    ) {
    }

    public function apply(int $maxCount, int $maxAgeDays, string $instanceId): void
    {
        $expired = $this->history->findExpired($maxCount, $maxAgeDays, self::MIN_KEEP);
        $dir = $this->storage->backupDirFor($instanceId);

        foreach ($expired as $row) {
            $this->storage->delete($dir . '/' . $row->cFilename);
            $this->storage->delete($dir . '/' . $row->cFilename . '.manifest.json');
            $this->history->delete((int) $row->kID);
        }
    }
}
