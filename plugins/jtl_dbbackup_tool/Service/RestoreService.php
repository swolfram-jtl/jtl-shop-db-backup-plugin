<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use Ifsnop\Mysqldump\Mysqldump;
use JTL\DB\DbInterface;

/**
 * Spec decisions covered here:
 * - "Pflicht-Vorab-Snapshot vor Restore": snapshot current state before
 *   restoring (default on, toggle-able — see spec "Optionalitätsprinzip").
 * - "Versions-/Struktur-Fingerprint-Block": compare manifest fingerprint to
 *   the live schema, hard-block on mismatch unless $forceOverride is set.
 * - "Konsistenzprüfung nach Teil-Restore": best-effort orphaned-row check,
 *   toggle-able like the above.
 * - "Restore-Vorschau vor Type-to-Confirm": row-count diff per affected
 *   table — approximate, parsed from the dump file rather than a real
 *   test-restore (spec explicitly calls this a "grober" diff).
 * - "Audit-Log-Integrität": PresetRegistry::ownTables() must never be passed
 *   in here as a restore scope — enforced by BackupController/HistoryController
 *   never offering "self" as a restorable preset, not re-checked in this class.
 * - "Passwort-Hashes bleiben im Backup": no column redaction anywhere here —
 *   restoring must not break customer login.
 */
final class RestoreService
{
    public function __construct(
        private readonly DbInterface $db,
        private readonly StorageService $storage,
        private readonly ManifestService $manifest,
        private readonly LockService $lock,
        private readonly BackupHistoryRepository $history,
        private readonly ConsistencyChecker $consistencyChecker,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryption,
        private readonly BackupService $backupService,
    ) {
    }

    /**
     * @return array{
     *     diff: array<string, array{before: int, after: int}>,
     *     versionWarnings: string[],
     * }
     */
    public function previewRestore(int $backupId, string $instanceId, ?string $decryptionPassphrase = null): array
    {
        $row = $this->history->find($backupId);
        if ($row === null) {
            throw new \RuntimeException(\d__('jtl_dbbackup_tool', 'Backup nicht gefunden.'));
        }

        $dir = $this->storage->backupDirFor($instanceId);
        $filePath = $dir . '/' . $row->cFilename;
        $manifest = $this->manifest->load($filePath . '.manifest.json');
        if ($manifest === null) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'Manifest-Datei zu diesem Backup fehlt oder ist unlesbar.'),
            );
        }

        $versionWarnings = $this->manifest->compareToLive($manifest);

        $plainDumpPath = $this->decryptIfNeeded($filePath, (bool) $row->bEncrypted, $decryptionPassphrase);

        $diff = [];
        foreach (\array_keys($manifest['tables']) as $table) {
            $diff[$table] = [
                'before' => $this->currentRowCount($table),
                'after'  => $this->countRowsInDump($plainDumpPath, $table),
            ];
        }

        if ($plainDumpPath !== $filePath) {
            $this->storage->delete($plainDumpPath);
        }

        return ['diff' => $diff, 'versionWarnings' => $versionWarnings];
    }

    public function restore(
        int $backupId,
        string $instanceId,
        int $adminAccountId,
        bool $preRestoreSnapshotEnabled,
        bool $versionFingerprintBlockEnabled,
        bool $consistencyCheckEnabled,
        bool $forceOverride,
        ?string $decryptionPassphrase = null,
    ): array {
        // See BackupService::createBackup() docblock for why: without this,
        // a slow restore can be hard-killed by PHP's execution-time limit,
        // skipping the finally{} below and leaving the lock looking stuck.
        @\set_time_limit(0);
        \ignore_user_abort(true);

        $this->lock->acquire();

        try {
            $row = $this->history->find($backupId);
            if ($row === null) {
                throw new \RuntimeException(\d__('jtl_dbbackup_tool', 'Backup nicht gefunden.'));
            }

            $dir = $this->storage->backupDirFor($instanceId);
            $filePath = $dir . '/' . $row->cFilename;
            $manifest = $this->manifest->load($filePath . '.manifest.json');
            if ($manifest === null) {
                throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'Manifest-Datei zu diesem Backup fehlt oder ist unlesbar.'),
            );
            }

            $versionWarnings = $this->manifest->compareToLive($manifest);
            if ($versionWarnings !== [] && $versionFingerprintBlockEnabled && !$forceOverride) {
                throw new \RuntimeException(\d__(
                    'jtl_dbbackup_tool',
                    'Restore blockiert: Tabellenstruktur weicht vom Backup-Zeitpunkt ab. %s',
                    \implode(' ', $versionWarnings),
                ));
            }

            $tables = \array_keys($manifest['tables']);
            if (\array_intersect($tables, PresetRegistry::ownTables()) !== []) {
                throw new \RuntimeException(\d__(
                    'jtl_dbbackup_tool',
                    'Plugin-eigene Tabellen dürfen nie über Restore überschrieben werden.',
                ));
            }

            if ($preRestoreSnapshotEnabled) {
                $this->backupService->createBackup($tables, $manifest['presetKey'], [
                    'label'                => \d__('jtl_dbbackup_tool', 'Automatischer Vorab-Snapshot vor Restore'),
                    'maintenanceMode'      => false,
                    'encrypt'              => false,
                    'encryptionPassphrase' => null,
                    'uploadTarget'         => null,
                    'adminAccountId'       => $adminAccountId,
                ]);
            }

            $plainDumpPath = $this->decryptIfNeeded($filePath, (bool) $row->bEncrypted, $decryptionPassphrase);

            [$dsn, $user, $pass] = $this->buildDsn();
            (new Mysqldump($dsn, $user, $pass))->restore($plainDumpPath);

            if ($plainDumpPath !== $filePath) {
                $this->storage->delete($plainDumpPath);
            }

            $this->auditLogger->log($adminAccountId, 'restore', $manifest['presetKey']);

            $consistencyWarnings = [];
            if ($consistencyCheckEnabled) {
                $consistencyWarnings = $this->consistencyChecker->checkAfterPartialRestore($manifest['presetKey']);
            }

            return ['versionWarnings' => $versionWarnings, 'consistencyWarnings' => $consistencyWarnings];
        } finally {
            $this->lock->release();
        }
    }

    private function currentRowCount(string $table): int
    {
        try {
            return $this->db->getSingleInt("SELECT COUNT(*) AS cnt FROM `{$table}`", 'cnt');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Approximate row count parsed directly from the (decompressed) dump —
     * NOT a real test-restore. Counts value-tuples in `INSERT INTO` lines for
     * the given table; matches the spec's own "grober Zeilen-Diff" framing.
     */
    private function countRowsInDump(string $plainSqlPath, string $table): int
    {
        $handle = @\fopen($plainSqlPath, 'rb');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        $needle = 'INSERT INTO `' . $table . '`';
        while (!\feof($handle)) {
            $line = \fgets($handle, 2_000_000);
            if ($line === false) {
                break;
            }
            if (\str_starts_with($line, $needle)) {
                $count += \substr_count($line, '),(') + 1;
            }
        }
        \fclose($handle);

        return $count;
    }

    /**
     * @return string plain (decompressed, decrypted) .sql path — may be a
     *         freshly-created temp file the caller must delete, or the
     *         original path if no transformation was needed.
     */
    private function decryptIfNeeded(string $filePath, bool $encrypted, ?string $passphrase): string
    {
        $working = $filePath;

        if ($encrypted) {
            if ($passphrase === null || $passphrase === '') {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Dieses Backup ist verschlüsselt — Passwort erforderlich.'),
                );
            }
            $decrypted = $this->storage->tmpPathFor($filePath) . '.decrypted';
            $this->encryption->decryptFile($working, $decrypted, $passphrase);
            $working = $decrypted;
        }

        // The dump is gzip-compressed regardless of encryption — decompress
        // to a plain .sql for both the row-count scan and Mysqldump::restore().
        $plainSql = $working . '.sql';
        $in = \gzopen($working, 'rb');
        $out = \fopen($plainSql, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'Backup-Datei konnte nicht entpackt werden.'),
            );
        }
        while (!\gzeof($in)) {
            \fwrite($out, \gzread($in, 1_000_000));
        }
        \gzclose($in);
        \fclose($out);

        if ($working !== $filePath) {
            $this->storage->delete($working);
        }

        return $plainSql;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * Same unverified DB_HOST/DB_NAME/DB_USER/DB_PASS assumption as
     * BackupService::buildDsn() — see that docblock.
     */
    private function buildDsn(): array
    {
        if (!\defined('DB_HOST') || !\defined('DB_NAME') || !\defined('DB_USER') || !\defined('DB_PASS')) {
            throw new \RuntimeException(
                'DB_HOST/DB_NAME/DB_USER/DB_PASS-Konstanten nicht gefunden — '
                . 'Verbindungsdaten für den Restore konnten nicht ermittelt werden.',
            );
        }

        $dsn = 'mysql:host=' . \DB_HOST . ';dbname=' . \DB_NAME . ';charset=utf8mb4';

        return [$dsn, \DB_USER, \DB_PASS];
    }
}
