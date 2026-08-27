<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Retention-Policy": configurable combo rule — max. count AND
 * max. age, oldest deleted first, with a minimum-keep floor so an aggressive
 * setting (e.g. "max age 1 day") can never wipe out every single backup and
 * leave the admin with no fallback at all. Applied PER preset/backup type
 * (see BackupHistoryRepository::findExpired()'s own docblock), not globally
 * across every backup combined — a frequently-run preset can never crowd out
 * a rarely-run one by filling a single shared limit.
 *
 * Spec decision "Bereinigung ist opt-in": this class itself has no on/off
 * switch — apply() always deletes whatever findExpired() returns. The actual
 * opt-in gate lives one level up, in the caller (BackupTrigger::trigger()),
 * which only constructs/calls this class at all when
 * SettingsRepository::autoCleanupEnabled() is true. Default: off.
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
