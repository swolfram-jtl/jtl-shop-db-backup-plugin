<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;

/**
 * Single place that wires BackupService's full dependency graph — factored
 * out after an ArgumentCountError surfaced in Bootstrap.php from a second,
 * incomplete copy of this same construction. BackupTrigger, HistoryController,
 * and Bootstrap::preUpdate() all use this now instead of each building their
 * own copy.
 */
final class BackupServiceFactory
{
    public static function build(PluginInterface $plugin, DbInterface $db): BackupService
    {
        $settings = new SettingsRepository($db);
        $storage = new StorageService(\dirname($plugin->getPaths()->getAdminPath()));
        $manifest = new ManifestService($db);
        $history = new BackupHistoryRepository($db);
        $audit = new AuditLogger($db);
        $notifications = new NotificationService($settings->notifyEmailOnFailure());
        $maintenance = new MaintenanceModeService($db);
        $encryption = new EncryptionService();
        $lock = new LockService($storage->baseDirectory() . '/.lock');

        return new BackupService(
            $db,
            $storage,
            $manifest,
            $lock,
            $history,
            $audit,
            $notifications,
            $maintenance,
            $encryption,
        );
    }
}
