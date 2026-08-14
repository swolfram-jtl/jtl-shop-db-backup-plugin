<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_dbbackup_tool\Service\AuditLogger;
use Plugin\jtl_dbbackup_tool\Service\BackupHistoryRepository;
use Plugin\jtl_dbbackup_tool\Service\BackupServiceFactory;
use Plugin\jtl_dbbackup_tool\Service\ConsistencyChecker;
use Plugin\jtl_dbbackup_tool\Service\EncryptionService;
use Plugin\jtl_dbbackup_tool\Service\LockService;
use Plugin\jtl_dbbackup_tool\Service\ManifestService;
use Plugin\jtl_dbbackup_tool\Service\RequestGuard;
use Plugin\jtl_dbbackup_tool\Service\RestoreService;
use Plugin\jtl_dbbackup_tool\Service\SettingsRepository;
use Plugin\jtl_dbbackup_tool\Service\StorageService;

/**
 * Spec decision "UI-Komponenten-Reuse": the backup list would reuse core's
 * own pagination.tpl / $oBlaetterNavi — NOT wired in this version, plain
 * un-paginated markup instead (Settingslink/pagination integration specifics
 * unverified; see README "Known gaps"). Functionally complete either way.
 * Spec decision "Restore-Confirm-UX": restore requires a fixed type-to-confirm
 * text ("RESTORE") — this is the single most safety-critical interaction in
 * the plugin (see spec section "Rechte & Nachvollziehbarkeit").
 */
final class HistoryController
{
    public function render(PluginInterface $plugin, JTLSmarty $smarty, DbInterface $db): string
    {
        $history = new BackupHistoryRepository($db);
        $storage = new StorageService(\dirname($plugin->getPaths()->getAdminPath()));
        $manifestService = new ManifestService($db);
        $instanceId = \substr($manifestService->instanceId(), 0, 32);

        $action = $_GET['action'] ?? $_POST['action'] ?? null;

        if ($action === 'download' && isset($_GET['id'])) {
            $this->handleDownload((int) $_GET['id'], $history, $storage, $instanceId);
            // handleDownload() exits — nothing below runs for a real download.
        }

        $flashMessage = null;
        $flashSuccess = true;
        $previewBackupId = null;
        $previewDiff = null;
        $previewVersionMismatch = false;
        $previewWarnings = null;
        $previewDecryptionPassphrase = null;

        $restoreService = $this->buildRestoreService($plugin, $db);
        $settings = new SettingsRepository($plugin);

        // POST only (never GET) — a decryption passphrase must never end up
        // in a URL/query string, so "preview" is submitted via a small form,
        // not a plain link, whenever the backup might be encrypted.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview' && isset($_POST['id']) && RequestGuard::claimRestoreAction()) {
            try {
                $result = $restoreService->previewRestore(
                    (int) $_POST['id'],
                    $instanceId,
                    $_POST['decryption_passphrase'] ?? null,
                );
                $previewBackupId = (string) (int) $_POST['id'];
                $previewDiff = $result['diff'];
                $previewVersionMismatch = $result['versionWarnings'] !== [];
                // Carried into a hidden field so the admin isn't asked to
                // retype it for the confirm step below — same trust boundary
                // (this admin's own POST to this same tab), never persisted.
                $previewDecryptionPassphrase = $_POST['decryption_passphrase'] ?? null;
            } catch (\Throwable $e) {
                $flashMessage = \d__('jtl_dbbackup_tool', 'Vorschau fehlgeschlagen: %s', $e->getMessage());
                $flashSuccess = false;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restore' && RequestGuard::claimRestoreAction()) {
            $adminAccountId = (int) ($_SESSION['AdminAccount']->kAdminlogin ?? 0);

            if (($_POST['confirmation'] ?? '') !== 'RESTORE') {
                $flashMessage = \d__(
                    'jtl_dbbackup_tool',
                    'Bestätigungstext stimmte nicht überein — Restore abgebrochen.',
                );
                $flashSuccess = false;
            } else {
                try {
                    $result = $restoreService->restore(
                        (int) $_POST['id'],
                        $instanceId,
                        $adminAccountId,
                        $settings->preRestoreSnapshotEnabled(),
                        $settings->versionFingerprintBlockEnabled(),
                        $settings->postRestoreConsistencyCheckEnabled(),
                        isset($_POST['force_override']),
                        $_POST['decryption_passphrase'] ?? null,
                    );
                    $flashMessage = \d__('jtl_dbbackup_tool', 'Restore abgeschlossen.');
                    if ($result['consistencyWarnings'] !== []) {
                        $flashMessage .= ' ' . \d__(
                            'jtl_dbbackup_tool',
                            'Hinweise: %s',
                            \implode(' ', $result['consistencyWarnings']),
                        );
                    }
                } catch (\Throwable $e) {
                    $flashMessage = \d__('jtl_dbbackup_tool', 'Restore fehlgeschlagen: %s', $e->getMessage());
                    $flashSuccess = false;
                }
            }
        }

        $backups = [];
        foreach ($history->all() as $row) {
            $backups[] = [
                'id'          => $row->kID,
                'dCreated'    => $row->dCreated,
                'cLabel'      => $row->cLabel,
                'cStatus'     => $row->cStatus,
                'nSizeBytes'  => $row->nSizeBytes / 1_000_000,
                'bEncrypted'  => (bool) $row->bEncrypted,
            ];
        }

        $smarty->assign('tplDir', \dirname(__DIR__) . '/adminmenu/templates')
            ->assign('backups', $backups)
            ->assign('flashMessage', $flashMessage)
            ->assign('flashSuccess', $flashSuccess)
            ->assign('previewBackupId', $previewBackupId)
            ->assign('previewDiff', $previewDiff)
            ->assign('previewWarnings', $previewWarnings)
            ->assign('previewVersionMismatch', $previewVersionMismatch)
            ->assign('previewDecryptionPassphrase', $previewDecryptionPassphrase);

        return $smarty->fetch(\dirname(__DIR__) . '/adminmenu/templates/history.tpl');
    }

    private function handleDownload(int $id, BackupHistoryRepository $history, StorageService $storage, string $instanceId): void
    {
        $row = $history->find($id);
        if ($row === null) {
            http_response_code(404);
            echo \d__('jtl_dbbackup_tool', 'Backup nicht gefunden.');
            exit;
        }

        $path = $storage->backupDirFor($instanceId) . '/' . $row->cFilename;
        if (!\file_exists($path)) {
            http_response_code(404);
            echo \d__('jtl_dbbackup_tool', 'Backup-Datei nicht gefunden.');
            exit;
        }

        \header('Content-Type: application/octet-stream');
        \header('Content-Disposition: attachment; filename="' . \basename($path) . '"');
        \header('Content-Length: ' . \filesize($path));
        \readfile($path);
        exit;
    }

    private function buildRestoreService(PluginInterface $plugin, DbInterface $db): RestoreService
    {
        $storage = new StorageService(\dirname($plugin->getPaths()->getAdminPath()));
        $manifest = new ManifestService($db);
        $history = new BackupHistoryRepository($db);
        $lock = new LockService($storage->baseDirectory() . '/.lock');
        $consistency = new ConsistencyChecker($db);
        $audit = new AuditLogger($db);
        $encryption = new EncryptionService();

        $backupService = BackupServiceFactory::build($plugin, $db);

        return new RestoreService($db, $storage, $manifest, $lock, $history, $consistency, $audit, $encryption, $backupService);
    }
}
