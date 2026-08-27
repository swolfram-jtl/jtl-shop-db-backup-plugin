<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_dbbackup_tool\Service\AuditLogger;
use Plugin\jtl_dbbackup_tool\Service\BackupDeletionService;
use Plugin\jtl_dbbackup_tool\Service\BackupHistoryRepository;
use Plugin\jtl_dbbackup_tool\Service\BackupServiceFactory;
use Plugin\jtl_dbbackup_tool\Service\ConsistencyChecker;
use Plugin\jtl_dbbackup_tool\Service\EncryptionService;
use Plugin\jtl_dbbackup_tool\Service\LockService;
use Plugin\jtl_dbbackup_tool\Service\ManifestService;
use Plugin\jtl_dbbackup_tool\Service\PresetRegistry;
use Plugin\jtl_dbbackup_tool\Service\RequestGuard;
use Plugin\jtl_dbbackup_tool\Service\RestoreService;
use Plugin\jtl_dbbackup_tool\Service\SettingsRepository;
use Plugin\jtl_dbbackup_tool\Service\StorageService;

/**
 * "Backups" tab — the DB Backup Manager: grouped-by-preset, filterable,
 * sortable, paginated list with inline comments, delete (single + bulk,
 * local-only — see BackupDeletionService), and the restore preview/confirm
 * flow (now surfaced in a modal instead of an inline page section).
 *
 * Filters/sort/page are read from $_GET (f_preset/f_status/f_storage/q/sort/
 * dir/page) — a GET form so the list stays bookmarkable/shareable, unlike
 * every mutating action here (preview/restore/delete/comment), which stays
 * POST-to-self like the rest of this plugin. Both compose cleanly: a POST
 * form with action="" submits to the CURRENT URL including its existing
 * query string, so a row action taken while filtered/sorted/paginated
 * reloads back onto that exact same view. Every form (GET and POST) still
 * carries cPluginTab="Backups" — see dashboard.tpl's header comment for why
 * (all tabs render into one page; without this hidden field, ANY request
 * without it bounces back to the Dashboard tab on reload).
 *
 * Spec decision "Restore-Confirm-UX": restore still requires the fixed
 * type-to-confirm text ("RESTORE") — unchanged, just relocated into the modal.
 * Spec decision "Löschen: nur lokal, einfacher Confirm für Einzel-Löschung,
 * Checkbox-Gate für Bulk-Löschung" — see BackupDeletionService + history.tpl.
 */
final class HistoryController
{
    private const PER_PAGE = 20;

    public function render(PluginInterface $plugin, JTLSmarty $smarty, DbInterface $db): string
    {
        $history = new BackupHistoryRepository($db);
        $storage = new StorageService(\dirname($plugin->getPaths()->getAdminPath()));
        $manifestService = new ManifestService($db);
        $instanceId = \substr($manifestService->instanceId(), 0, 32);
        $lock = new LockService($storage->baseDirectory() . '/.lock');

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
        $adminAccountId = (int) ($_SESSION['AdminAccount']->kAdminlogin ?? 0);

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
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restore' && RequestGuard::claimRestoreAction()) {
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
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'comment' && isset($_POST['id']) && RequestGuard::claimCommentAction()) {
            $history->updateComment((int) $_POST['id'], \trim((string) ($_POST['comment'] ?? '')));
            $flashMessage = \d__('jtl_dbbackup_tool', 'Kommentar gespeichert.');
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && isset($_POST['id']) && RequestGuard::claimDeleteAction()) {
            try {
                $this->buildDeletionService($storage, $history, $lock, $db)->delete((int) $_POST['id'], $instanceId, $adminAccountId);
                $flashMessage = \d__('jtl_dbbackup_tool', 'Backup gelöscht.');
            } catch (\Throwable $e) {
                $flashMessage = \d__('jtl_dbbackup_tool', 'Löschen fehlgeschlagen: %s', $e->getMessage());
                $flashSuccess = false;
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_delete' && !empty($_POST['ids']) && RequestGuard::claimDeleteAction()) {
            $ids = \array_map('intval', (array) $_POST['ids']);
            $result = $this->buildDeletionService($storage, $history, $lock, $db)->deleteMany($ids, $instanceId, $adminAccountId);
            $deletedCount = \count($result['deleted']);
            if ($result['failed'] === []) {
                $flashMessage = \d__('jtl_dbbackup_tool', '%d Backup(s) gelöscht.', $deletedCount);
            } else {
                $flashMessage = \d__(
                    'jtl_dbbackup_tool',
                    '%d Backup(s) gelöscht, %d fehlgeschlagen: %s',
                    $deletedCount,
                    \count($result['failed']),
                    \implode('; ', $result['failed']),
                );
                $flashSuccess = $deletedCount > 0;
            }
        }

        // --- Filter/sort/page (GET) ---
        $filterPreset = (string) ($_GET['f_preset'] ?? '');
        $filterStatus = (string) ($_GET['f_status'] ?? '');
        $filterStorage = (string) ($_GET['f_storage'] ?? '');
        $search = \trim((string) ($_GET['q'] ?? ''));
        $sortField = \in_array($_GET['sort'] ?? '', ['date', 'size', 'status'], true) ? $_GET['sort'] : 'date';
        $sortDir = \strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $page = \max(1, (int) ($_GET['page'] ?? 1));

        $result = $history->search(
            ['presetKey' => $filterPreset ?: null, 'status' => $filterStatus ?: null, 'storage' => $filterStorage ?: null, 'search' => $search ?: null],
            $sortField,
            $sortDir,
            $page,
            self::PER_PAGE,
        );

        $presetLabels = $this->presetLabels();
        $groups = [];
        foreach ($result['rows'] as $row) {
            $key = $row->cPresetKey;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'presetKey'   => $key,
                    'presetLabel' => $presetLabels[$key] ?? $key,
                    'rows'        => [],
                ];
            }
            $groups[$key]['rows'][] = [
                'id'         => $row->kID,
                'dCreated'   => $row->dCreated,
                'cLabel'     => $row->cLabel,
                'cComment'   => $row->cComment ?? '',
                'cStatus'    => $row->cStatus,
                'nSizeBytes' => $row->nSizeBytes / 1_000_000,
                'bEncrypted' => (bool) $row->bEncrypted,
                'bUploaded'  => (bool) $row->bUploaded,
            ];
        }

        $totalPages = \max(1, (int) \ceil($result['total'] / self::PER_PAGE));
        $pageNumbers = \range(1, $totalPages);

        $baseParams = \array_filter([
            'cPluginTab' => 'Backups',
            'f_preset'   => $filterPreset,
            'f_status'   => $filterStatus,
            'f_storage'  => $filterStorage,
            'q'          => $search,
        ]);

        // Pagination links: current filters + current sort, page varies.
        $filterQuery = \http_build_query($baseParams + ['sort' => $sortField, 'dir' => $sortDir]);

        // Column-header sort links: current filters, sort/dir TOGGLED per column
        // (page reset to 1 — a changed sort makes the old page number meaningless).
        $sortLinks = [];
        foreach (['date', 'size', 'status'] as $field) {
            $nextDir = ($sortField === $field && $sortDir === 'DESC') ? 'ASC' : 'DESC';
            $sortLinks[$field] = '?' . \http_build_query($baseParams + ['sort' => $field, 'dir' => $nextDir]);
        }

        $smarty->assign('tplDir', \dirname(__DIR__) . '/adminmenu/templates')
            ->assign('groups', \array_values($groups))
            ->assign('presetOptions', $presetLabels)
            ->assign('filterPreset', $filterPreset)
            ->assign('filterStatus', $filterStatus)
            ->assign('filterStorage', $filterStorage)
            ->assign('search', $search)
            ->assign('sortField', $sortField)
            ->assign('sortDir', $sortDir)
            ->assign('sortLinks', $sortLinks)
            ->assign('page', $page)
            ->assign('totalPages', $totalPages)
            ->assign('pageNumbers', $pageNumbers)
            ->assign('totalCount', $result['total'])
            ->assign('filterQuery', $filterQuery)
            ->assign('isLocked', $lock->isLocked())
            ->assign('flashMessage', $flashMessage)
            ->assign('flashSuccess', $flashSuccess)
            ->assign('previewBackupId', $previewBackupId)
            ->assign('previewDiff', $previewDiff)
            ->assign('previewWarnings', $previewWarnings)
            ->assign('previewVersionMismatch', $previewVersionMismatch)
            ->assign('previewDecryptionPassphrase', $previewDecryptionPassphrase);

        return $smarty->fetch(\dirname(__DIR__) . '/adminmenu/templates/history.tpl');
    }

    /**
     * @return array<string, string> presetKey => display label. Synthetic
     *         entries ('full', 'self_update') go through \d__() since they're
     *         pure UI labels; PresetRegistry's own labels stay untranslated
     *         (spec "Preset-Benennung" — must match the shop's own backend
     *         menu wording verbatim).
     */
    private function presetLabels(): array
    {
        $labels = ['full' => \d__('jtl_dbbackup_tool', 'Komplett')];
        foreach (PresetRegistry::all() as $key => $preset) {
            $labels[$key] = $preset['label'];
        }
        $labels['self_update'] = \d__('jtl_dbbackup_tool', 'Automatisches Backup vor Plugin-Update');

        return $labels;
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

    private function buildDeletionService(
        StorageService $storage,
        BackupHistoryRepository $history,
        LockService $lock,
        DbInterface $db,
    ): BackupDeletionService {
        return new BackupDeletionService($storage, $history, $lock, new AuditLogger($db));
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
