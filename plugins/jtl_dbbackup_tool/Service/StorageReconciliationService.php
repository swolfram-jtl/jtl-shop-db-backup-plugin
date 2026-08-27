<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Speicherort außerhalb des Webroots" (StorageService) means
 * backup files deliberately outlive the plugin itself — an uninstall/
 * reinstall cycle (or restoring the shop's own database from an older dump)
 * drops and recreates `xplugin_jtl_dbbackup_tool_backuphistory` empty, while
 * the actual `.sql.gz`(`.enc`) files + their `.manifest.json` sidecars keep
 * sitting on disk untouched. Without this, the Manager tab would then show
 * "no backups" despite real, restorable files being right there — reported
 * as a real bug after a reinstall.
 *
 * Reconciliation is purely additive and read-only towards the filesystem:
 * it only ever INSERTs a history row for a file that has both a committed
 * data file and a readable `.manifest.json` and is not already tracked
 * (matched by exact filename) — it never deletes or modifies an existing
 * row, and a file without a manifest (or whose manifest fails to parse) is
 * silently left alone rather than guessed at. Recovered rows are flagged
 * with a comment noting the original creator/upload-status are unknown,
 * since neither survives in the manifest.
 */
final class StorageReconciliationService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly ManifestService $manifest,
        private readonly BackupHistoryRepository $history,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return int number of history rows recovered
     */
    public function reconcile(string $instanceId, int $adminAccountId): int
    {
        $dir = $this->storage->backupDirFor($instanceId);
        $known = \array_flip($this->history->allFilenamesForInstance($instanceId));

        $recovered = 0;
        foreach (\glob($dir . '/*.manifest.json') ?: [] as $manifestPath) {
            $dataFile = \substr($manifestPath, 0, -\strlen('.manifest.json'));
            $filename = \basename($dataFile);

            if (isset($known[$filename]) || !\file_exists($dataFile)) {
                continue;
            }

            $data = $this->manifest->load($manifestPath);
            $presetKey = $data['presetKey'] ?? null;
            // Every caller of this service passes instanceId already
            // truncated to 32 chars (matches the storage directory name),
            // but ManifestService::build() stores the FULL untruncated hash
            // inside the manifest itself — truncate the same way here, or
            // this comparison would never match anything.
            $manifestInstanceId = \substr((string) ($data['instanceId'] ?? ''), 0, 32);
            if ($data === null || $presetKey === null || $manifestInstanceId !== $instanceId) {
                continue;
            }

            $this->history->createRecovered(
                $presetKey,
                PresetLabelResolver::get($presetKey),
                \d__(
                    'jtl_dbbackup_tool',
                    'Automatisch von der Festplatte wiederhergestellt (z. B. nach Plugin-Neuinstallation) — ursprünglicher Ersteller und Upload-Status sind nicht mehr bekannt.',
                ),
                $filename,
                $instanceId,
                (bool) ($data['encrypted'] ?? false),
                (int) \filesize($dataFile),
                (string) ($data['formatVersion'] ?? ManifestService::MANIFEST_FORMAT_VERSION),
                $this->resolveCreatedAt($data, $dataFile),
            );
            $recovered++;
        }

        if ($recovered > 0) {
            $this->auditLogger->log(
                $adminAccountId,
                'reconcile',
                $instanceId,
                \d__('jtl_dbbackup_tool', '%d Backup(s) von der Festplatte wiederhergestellt.', $recovered),
            );
        }

        return $recovered;
    }

    private function resolveCreatedAt(array $manifestData, string $dataFile): string
    {
        $iso = $manifestData['createdAt'] ?? null;
        if (\is_string($iso) && $iso !== '') {
            try {
                return (new \DateTimeImmutable($iso))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // fall through to filemtime below
            }
        }

        $mtime = @\filemtime($dataFile);

        return $mtime !== false ? \date('Y-m-d H:i:s', $mtime) : \date('Y-m-d H:i:s');
    }
}
