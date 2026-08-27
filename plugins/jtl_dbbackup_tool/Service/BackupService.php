<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use Ifsnop\Mysqldump\Mysqldump;
use JTL\DB\DbInterface;
use Plugin\jtl_dbbackup_tool\Service\Upload\UploadTargetInterface;

/**
 * Options resolved by the caller (Controller/Cron job) from plugin settings —
 * kept as a plain array here deliberately, so this class doesn't need to
 * know the exact JTL\Plugin\Data\Config read API (that's isolated to the
 * SettingsRepository class instead).
 *
 * @phpstan-type BackupOptions array{
 *     label: string,
 *     maintenanceMode: bool,
 *     encrypt: bool,
 *     encryptionPassphrase: ?string,
 *     uploadTarget: ?UploadTargetInterface,
 *     adminAccountId: int,
 *     comment?: ?string,
 * }
 */
final class BackupService
{
    public function __construct(
        private readonly DbInterface $db,
        private readonly StorageService $storage,
        private readonly ManifestService $manifest,
        private readonly LockService $lock,
        private readonly BackupHistoryRepository $history,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifications,
        private readonly MaintenanceModeService $maintenanceMode,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function assertDependencyAvailable(): void
    {
        if (!\class_exists(Mysqldump::class)) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'Diese Shop-Version stellt die für Backups benötigte Bibliothek '
                . '(ifsnop/mysqldump-php) nicht mehr bereit. Bitte Plugin-Update abwarten.',
            ));
        }
    }

    /**
     * @param string[] $tables
     * @param array{
     *     label: string, maintenanceMode: bool, encrypt: bool,
     *     encryptionPassphrase: ?string, uploadTarget: ?UploadTargetInterface,
     *     adminAccountId: int, comment?: ?string,
     * } $options
     */
    public function createBackup(array $tables, string $presetKey, array $options): int
    {
        $this->assertDependencyAvailable();

        // A large "Komplett" dump on a real production DB can run long —
        // mysqldump-php is pure PHP, noticeably slower than a native
        // mysqldump binary. Without these, PHP's default execution-time
        // limit (or the browser tab being closed/navigated away) can kill
        // the request mid-dump, which — being a hard kill, not a catchable
        // exception — skips the finally{} below and leaves the lock file
        // looking "stuck" even though nothing is actually wrong with the
        // lock mechanism itself (see LockService's docblock).
        @\set_time_limit(0);
        \ignore_user_abort(true);

        $this->lock->acquire();

        $instanceId = \substr($this->manifest->instanceId(), 0, 32);
        $dir = $this->storage->backupDirFor($instanceId);
        $timestamp = \date('Ymd_His');
        $filename = "{$presetKey}_{$timestamp}_{$instanceId}.sql.gz";
        $finalPath = $dir . '/' . $filename;

        $historyId = $this->history->create(
            $presetKey,
            $options['label'],
            $filename,
            $instanceId,
            $options['encrypt'],
            $options['comment'] ?? null,
        );
        $maintenanceEnabled = false;

        try {
            $this->assertEnoughDiskSpace($tables, $dir);

            if ($options['maintenanceMode']) {
                $maintenanceEnabled = $this->maintenanceMode->enable();
            }

            $tmpDumpPath = $this->storage->tmpPathFor($finalPath) . '.raw';
            $this->dump($tables, $tmpDumpPath);

            // Spec "Wartungsmodus": lifted immediately after the local dump —
            // upload/encryption below must never keep the shop offline longer
            // than the dump itself took.
            if ($maintenanceEnabled) {
                $this->maintenanceMode->disable();
                $maintenanceEnabled = false;
            }

            $selfTestOk = $this->selfTest($tmpDumpPath);
            if (!$selfTestOk) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Backup-Selbsttest fehlgeschlagen — Datei scheint beschädigt.'),
                );
            }

            if ($options['encrypt']) {
                if ($options['encryptionPassphrase'] === null || $options['encryptionPassphrase'] === '') {
                    throw new \RuntimeException(
                        \d__('jtl_dbbackup_tool', 'Verschlüsselung aktiviert, aber kein Passwort angegeben.'),
                    );
                }
                $encryptedPath = $tmpDumpPath . '.enc';
                $this->encryption->encryptFile($tmpDumpPath, $encryptedPath, $options['encryptionPassphrase']);
                $this->storage->delete($tmpDumpPath);
                $tmpDumpPath = $encryptedPath;
            }

            $this->storage->commit($tmpDumpPath, $finalPath);

            $manifest = $this->manifest->build($tables, $presetKey, $options['encrypt'], $options['comment'] ?? null);
            $this->manifest->save($manifest, $finalPath . '.manifest.json');

            $sizeBytes = (int) \filesize($finalPath);
            $this->history->markResult($historyId, 'ok', $sizeBytes);
            $this->auditLogger->log($options['adminAccountId'], 'backup', $presetKey);

            if ($options['uploadTarget'] !== null) {
                $this->tryUpload($options['uploadTarget'], $finalPath, $filename, $historyId);
            }

            return $historyId;
        } catch (\Throwable $e) {
            $this->history->markResult($historyId, 'failed', 0, $e->getMessage());
            $this->notifications->notifyFailure(
                \d__('jtl_dbbackup_tool', 'Backup fehlgeschlagen'),
                \d__('jtl_dbbackup_tool', "Preset: %s\nFehler: %s", $presetKey, $e->getMessage()),
            );

            throw $e;
        } finally {
            if ($maintenanceEnabled) {
                $this->maintenanceMode->disable();
            }
            $this->lock->release();
        }
    }

    /**
     * Spec decision "Selbst-Backup bei eigenem Update": snapshot the plugin's
     * own tables before its own schema migration runs.
     */
    public function backupOwnTables(): void
    {
        $this->createBackup(PresetRegistry::ownTables(), 'self_update', [
            'label'                 => \d__('jtl_dbbackup_tool', 'Automatisches Backup vor Plugin-Update'),
            'maintenanceMode'       => false,
            'encrypt'               => false,
            'encryptionPassphrase'  => null,
            'uploadTarget'          => null,
            'adminAccountId'        => 0,
        ]);
    }

    /**
     * @param string[] $tables
     */
    private function dump(array $tables, string $outputPath): void
    {
        [$dsn, $user, $pass] = $this->buildDsn();

        $dump = new Mysqldump($dsn, $user, $pass, [
            'include-tables'      => $tables,
            'single-transaction'  => true, // spec "Konsistenz-Fix": real InnoDB MVCC snapshot
            'lock-tables'         => false,
            'add-drop-table'      => true,
            'skip-triggers'       => false,
            'compress'            => Mysqldump::GZIP,
        ]);
        $dump->start($outputPath);
    }

    /**
     * @param string[] $tables
     */
    private function assertEnoughDiskSpace(array $tables, string $dir): void
    {
        $estimatedBytes = 0;
        foreach ($tables as $table) {
            try {
                $estimatedBytes += $this->db->getSingleInt(
                    'SELECT COALESCE(data_length + index_length, 0) AS sz FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() AND table_name = :t',
                    'sz',
                    ['t' => $table],
                );
            } catch (\Throwable) {
                // best-effort estimate only
            }
        }

        $free = $this->storage->freeDiskSpaceBytes();
        if ($free !== false && $estimatedBytes > 0 && $free < ($estimatedBytes * 1.2)) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'Zu wenig freier Speicherplatz für dieses Backup (benötigt ca. %.1f MB, verfügbar %.1f MB).',
                $estimatedBytes / 1_000_000,
                $free / 1_000_000,
            ));
        }
    }

    private function selfTest(string $path): bool
    {
        if (!\file_exists($path) || \filesize($path) === 0) {
            return false;
        }

        $handle = @\gzopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = \gzread($handle, 64);
        \gzclose($handle);

        return $head !== false && \str_contains($head, 'SQL Dump') || \str_contains((string) $head, '--');
    }

    private function tryUpload(UploadTargetInterface $target, string $localPath, string $filename, int $historyId): void
    {
        try {
            $target->upload($localPath, $filename);
            $target->upload($localPath . '.manifest.json', $filename . '.manifest.json');
            $this->history->markUploaded($historyId, true);
        } catch (\Throwable $e) {
            // Spec "Upload-Fehlerfall": local backup already succeeded and stays
            // valid — an upload failure must never fail the whole backup run.
            $this->history->markUploaded($historyId, false, $e->getMessage());
            $this->notifications->notifyFailure(
                \d__('jtl_dbbackup_tool', 'Backup-Upload fehlgeschlagen'),
                \d__(
                    'jtl_dbbackup_tool',
                    "Datei: %s\nFehler: %s\nLokales Backup ist weiterhin vorhanden.",
                    $filename,
                    $e->getMessage(),
                ),
            );
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} [dsn, user, password]
     *
     * NOT independently verified: assumes the classic JTL-Shop convention of
     * DB_HOST/DB_NAME/DB_USER/DB_PASS constants from config.JTL-Shop.ini.php
     * — mysqldump-php needs raw connection credentials to open its OWN PDO
     * connection (it can't reuse NiceDB's internal PDO handle). Verify these
     * constant names against a real shop install before relying on this.
     */
    private function buildDsn(): array
    {
        if (!\defined('DB_HOST') || !\defined('DB_NAME') || !\defined('DB_USER') || !\defined('DB_PASS')) {
            throw new \RuntimeException(
                'DB_HOST/DB_NAME/DB_USER/DB_PASS-Konstanten nicht gefunden — '
                . 'Verbindungsdaten für den Dump konnten nicht ermittelt werden.',
            );
        }

        $dsn = 'mysql:host=' . \DB_HOST . ';dbname=' . \DB_NAME . ';charset=utf8mb4';

        return [$dsn, \DB_USER, \DB_PASS];
    }
}
