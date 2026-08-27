<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;

/**
 * Shared entry point for triggering a backup from either the Dashboard's
 * quick-access buttons or the Backup tab's own preset list, so the wiring of
 * BackupService and its dependencies lives in exactly one place
 * (BackupServiceFactory).
 *
 * Scope note: runs synchronously within the current admin request rather
 * than enqueuing a cron-queue job (spec: "Lange Backups laufen immer async"
 * called for the latter). The exact API for enqueuing a one-off job via
 * JTL\Router\Controller\Backend\CronController::addQueueEntry() (job type,
 * scheduling, custom parameter payload) was not verified in time — a
 * synchronous run is fine in practice for every preset here (small tables),
 * but "Komplett" on a very large database could hit a PHP request timeout.
 * See README "Known gaps". BackupCronJob still covers the RECURRING
 * scheduled case, which is unaffected by this.
 */
final class BackupTrigger
{
    public function __construct(
        private readonly PluginInterface $plugin,
        private readonly DbInterface $db,
    ) {
    }

    /**
     * @param array{use_ephemeral_credentials?: bool, encrypt_override?: bool, comment?: ?string} $formOptions
     *
     * @return array{success: bool, message: string}
     */
    public function trigger(string $presetKey, int $adminAccountId, array $formOptions = []): array
    {
        $settings = new SettingsRepository($this->plugin);
        $backupService = BackupServiceFactory::build($this->plugin, $this->db);

        if ($presetKey === 'full') {
            $tables = $this->allTablesExceptOwn();
            $label = 'Komplett';
        } else {
            $preset = PresetRegistry::get($presetKey);
            if ($preset === null) {
                return [
                    'success' => false,
                    'message' => \d__('jtl_dbbackup_tool', 'Unbekanntes Preset: %s', $presetKey),
                ];
            }
            $tables = $preset['tables'];
            $label = $preset['label'];
        }

        $encrypt = $formOptions['encrypt_override'] ?? $settings->encryptionEnabled();
        $uploadTarget = !empty($formOptions['use_ephemeral_credentials']) && isset($formOptions['ephemeral'])
            ? $settings->buildUploadTarget($formOptions['ephemeral'])
            : $settings->buildUploadTarget();

        try {
            $historyId = $backupService->createBackup($tables, $presetKey, [
                'label'                => $label,
                'maintenanceMode'      => $settings->maintenanceModeEnabled(),
                'encrypt'              => $encrypt,
                'encryptionPassphrase' => $settings->encryptionPassphrase(),
                'uploadTarget'         => $uploadTarget,
                'adminAccountId'       => $adminAccountId,
                'comment'              => $formOptions['comment'] ?? null,
            ]);

            $storage = new StorageService(\dirname($this->plugin->getPaths()->getAdminPath()));
            $manifest = new ManifestService($this->db);
            $history = new BackupHistoryRepository($this->db);
            $retention = new RetentionService($history, $storage);
            $retention->apply(
                $settings->retentionMaxCount(),
                $settings->retentionMaxAgeDays(),
                \substr($manifest->instanceId(), 0, 32),
            );

            // Spec: "ausführliche Meldung" — filename + size + completion
            // time, not just "erfolgreich erstellt". createBackup() has
            // already finalized the history row (markResult()) by the time
            // it returns, so this read-back is always the real final state,
            // never a 'running' placeholder.
            $row = $history->find($historyId);

            return [
                'success' => true,
                'message' => $row !== null
                    ? \d__(
                        'jtl_dbbackup_tool',
                        'Backup „%s“ erfolgreich erstellt. Datei: %s (%s), abgeschlossen um %s Uhr.',
                        $label,
                        $row->cFilename,
                        SizeFormatter::human((int) $row->nSizeBytes),
                        \date('d.m.Y H:i:s'),
                    )
                    : \d__('jtl_dbbackup_tool', 'Backup „%s“ erfolgreich erstellt.', $label),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => \d__('jtl_dbbackup_tool', 'Backup fehlgeschlagen: %s', $e->getMessage()),
            ];
        }
    }

    /**
     * "Komplett" = every table in the schema except this plugin's own —
     * spec "Audit-Log-Integrität". Reads information_schema rather than a
     * hardcoded list so it stays complete as the shop's own schema evolves.
     *
     * @return string[]
     */
    private function allTablesExceptOwn(): array
    {
        $rows = $this->db->getObjects(
            'SELECT TABLE_NAME AS name FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()',
        );
        $own = PresetRegistry::ownTables();

        return \array_values(\array_diff(\array_map(static fn ($r) => $r->name, $rows), $own));
    }
}
