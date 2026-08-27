<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_dbbackup_tool\Service\AuditLogger;
use Plugin\jtl_dbbackup_tool\Service\BackupHistoryRepository;
use Plugin\jtl_dbbackup_tool\Service\BackupTrigger;
use Plugin\jtl_dbbackup_tool\Service\LockService;
use Plugin\jtl_dbbackup_tool\Service\ManifestService;
use Plugin\jtl_dbbackup_tool\Service\PresetRegistry;
use Plugin\jtl_dbbackup_tool\Service\RequestGuard;
use Plugin\jtl_dbbackup_tool\Service\SettingsRepository;
use Plugin\jtl_dbbackup_tool\Service\StorageReconciliationService;
use Plugin\jtl_dbbackup_tool\Service\StorageService;

/**
 * Spec decision "Dashboard-Inhalt": status tiles (last backup, next scheduled,
 * storage used locally + FTP, backup count), preset quick-access buttons for
 * all seven presets, and a warning banner when the last scheduled run failed.
 * Spec decision "Leerzustand": a first-run call-to-action when zero backups exist.
 *
 * Self-contained: the quick-access buttons POST back to this same tab
 * (action="") rather than linking to the Backup tab, since the exact
 * cross-tab URL scheme for this backend wasn't independently verified.
 * The same reasoning applies to the recent-activity download buttons below:
 * rather than link across to the Wiederherstellen tab's download handler,
 * this controller handles its own `?action=download&id=` GET directly — and
 * since every Customlink file executes on every request (see RequestGuard),
 * whichever controller's check matches first serves the file identically;
 * no cross-tab link scheme needs to be known at all.
 */
final class DashboardController
{
    public function render(PluginInterface $plugin, JTLSmarty $smarty, DbInterface $db): string
    {
        $history = new BackupHistoryRepository($db);
        $storage = new StorageService(\dirname($plugin->getPaths()->getAdminPath()));
        $lock = new LockService($storage->baseDirectory() . '/.lock');
        $settings = new SettingsRepository($plugin);
        $manifestService = new ManifestService($db);
        $instanceId = \substr($manifestService->instanceId(), 0, 32);

        if (($_GET['action'] ?? null) === 'download' && isset($_GET['id'])) {
            $this->handleDownload((int) $_GET['id'], $history, $storage, $instanceId);
            // handleDownload() exits — nothing below runs for a real download.
        }

        // Same self-healing as HistoryController::render() — see
        // StorageReconciliationService's docblock. Without this here too,
        // the Dashboard's own tiles/recent-activity would keep showing 0
        // for one extra page load after a reinstall, even after the Backups
        // tab has already caught up (each Adminmenu tab file queries the DB
        // independently within the same request).
        (new StorageReconciliationService($storage, $manifestService, $history, new AuditLogger($db)))
            ->reconcile($instanceId, (int) ($_SESSION['AdminAccount']->kAdminlogin ?? 0));

        $flashMessage = null;
        $flashSuccess = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_unlock']) && RequestGuard::claimBackupTrigger()) {
            // Spec-adjacent safety valve, admin-confirmed only — see
            // LockService::forceRelease() docblock for why this must never
            // be automatic.
            $lock->forceRelease();
            $flashMessage = \d__('jtl_dbbackup_tool', 'Sperre wurde manuell aufgehoben.');
            $flashSuccess = true;
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preset']) && RequestGuard::claimBackupTrigger()) {
            $adminAccountId = (int) ($_SESSION['AdminAccount']->kAdminlogin ?? 0);
            $result = (new BackupTrigger($plugin, $db))->trigger((string) $_POST['preset'], $adminAccountId);
            $flashMessage = $result['message'];
            $flashSuccess = $result['success'];
        }

        $latest = $history->latest();
        $count = $history->count();

        $presets = [];
        foreach (PresetRegistry::all() as $key => $preset) {
            $presets[$key] = $preset['label'];
        }

        $recent = [];
        foreach ($history->all(5) as $row) {
            $recent[] = [
                'id'         => $row->kID,
                'dCreated'   => $row->dCreated,
                'cLabel'     => $row->cLabel,
                'cStatus'    => $row->cStatus,
                'nSizeBytes' => $row->nSizeBytes,
            ];
        }

        $smarty->assign('tplDir', \dirname(__DIR__) . '/adminmenu/templates')
            ->assign('hasAnyBackup', $count > 0)
            ->assign('lastBackup', $latest !== null ? [
                'dCreated' => $latest->dCreated,
                'cStatus'  => $latest->cStatus,
                'cLabel'   => $latest->cLabel,
            ] : null)
            ->assign('lastRunFailed', $latest !== null && $latest->cStatus === 'failed')
            ->assign('lastRunError', $latest->cLastError ?? null)
            ->assign('nextScheduled', null) // TODO: read from the shop's cron queue once that read API is confirmed
            ->assign('storageLocalBytes', $this->dirSizeMb($storage))
            ->assign('storageFtpBytes', null) // remote size isn't queried to avoid a network round-trip on every dashboard load
            ->assign('backupCount', $count)
            ->assign('presets', $presets)
            ->assign('recent', $recent)
            ->assign('isLocked', $lock->isLocked())
            ->assign('lockedSince', $lock->lockedSince()?->format('Y-m-d H:i:s'))
            ->assign('storageLocalPath', $storage->baseDirectory())
            ->assign('ftpSummary', $settings->ftpSummary())
            ->assign('flashMessage', $flashMessage)
            ->assign('flashSuccess', $flashSuccess);

        return $smarty->fetch(\dirname(__DIR__) . '/adminmenu/templates/dashboard.tpl');
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

    private function dirSizeMb(StorageService $storage): float
    {
        $total = 0;
        foreach (\glob($storage->baseDirectory() . '/*/*') ?: [] as $file) {
            $total += \is_file($file) ? \filesize($file) : 0;
        }

        return $total / 1_000_000;
    }
}
