<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool;

use JTL\Events\Dispatcher;
use JTL\Events\Event;
use JTL\Plugin\Bootstrapper;
use Plugin\jtl_dbbackup_tool\Cron\BackupCronJob;

// Optional, separately-vendored dependency for SFTP support only (phpseclib3)
// — see Service/Upload/SftpUploadTarget.php and composer.json in this folder.
// Safe to skip silently if never installed: SftpUploadTarget's own
// assertLibraryAvailable() gives a clear admin-facing error at the point of
// use, FTPS and local-only backups work fine without it either way.
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (\is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

/**
 * Lifecycle method signatures below are verified verbatim against
 * includes/src/Plugin/BootstrapperInterface.php and Bootstrapper.php
 * (release/5.8.0) — none of them are abstract, all are safe to leave
 * un-overridden, and none of the overridden ones here declare parameter or
 * return types beyond what the interface itself declares (it leaves
 * $oldVersion/$newVersion untyped, and boot()/installed()/enabled()/
 * disabled() undeclared for return type).
 */
class Bootstrap extends Bootstrapper
{
    private const CRON_JOB_TYPE = 'plugin:jtl_dbbackup_tool_cron';

    public function boot(Dispatcher $dispatcher)
    {
        parent::boot($dispatcher);

        // Spec: "Scheduling" — optional cron backup, independent of the manual button.
        $dispatcher->listen(Event::GET_AVAILABLE_CRONJOBS, function (array $args): array {
            $args['jobTypes'][self::CRON_JOB_TYPE] = 'Datenbank-Backup (Plugin)';

            return $args;
        });

        $dispatcher->listen(Event::MAP_CRONJOB_TYPE, function (array $args): array {
            if ($args['jobType'] === self::CRON_JOB_TYPE) {
                $args['jobClass'] = BackupCronJob::class;
            }

            return $args;
        });
    }

    /**
     * Spec decision "Dependency-Isolation-Risiko" originally wanted this to
     * refuse installation outright if core no longer ships
     * Ifsnop\Mysqldump\Mysqldump. Walked back: preInstallCheck() returning
     * false is a silent, unexplained rejection with no way for this class to
     * attach a reason — precisely the kind of unhelpful failure that's easy
     * to mistake for a broken info.xml/Bootstrap.php. The same check now
     * lives in BackupService::assertDependencyAvailable(), which throws a
     * real, visible error message at the point it's actually needed (the
     * first backup attempt) instead of gatekeeping installation itself.
     */
    public function preInstallCheck(): bool
    {
        return true;
    }

    public function installed()
    {
        // Verified: Migrations/* run automatically — MigrationManager
        // (includes/src/Plugin/Admin/Installation/MigrationManager.php) is
        // invoked from Installer.php as part of the install flow itself,
        // before this hook fires. Default setting values come from each
        // <Setting initialValue="..."> in info.xml. Nothing left to do here.
    }

    /**
     * $deleteData reflects the admin's choice in the shop's own uninstall
     * dialog. Spec decision "Deinstallation" overrides that for THIS plugin:
     * backup files and FTP configuration are always kept regardless of
     * $deleteData, with only a warning shown elsewhere in the uninstall UI —
     * a backup tool must not delete the safety net it exists to provide.
     */
    public function uninstalled(bool $deleteData = true)
    {
        // Intentionally no-op even when $deleteData is true — see docblock above.
    }

    public function enabled()
    {
        // TODO: re-register the cron queue entry if it was removed on disable.
    }

    public function disabled()
    {
        // TODO: pause/remove the cron queue entry (JTL\Router\Controller\Backend\CronController).
    }

    public function preUpdate($oldVersion, $newVersion): void
    {
        // Spec decision "Selbst-Backup bei eigenem Update": back up the plugin's
        // own tables (settings, audit log, backup history) BEFORE the automatic
        // Migrations/* run (MigrationManager runs after this hook, still inside
        // the same Installer flow), mirroring the tool's own philosophy.
        //
        // Fixed: this used to construct BackupService with 4 of its 9 required
        // constructor arguments (an ArgumentCountError, though harmless here
        // since it's swallowed by the catch below — still a real bug, and
        // preUpdate() only ever runs on a plugin UPDATE, never on first
        // install/upload).
        try {
            $db = \JTL\Shop::Container()->getDB();
            \Plugin\jtl_dbbackup_tool\Service\BackupServiceFactory::build($this->getPlugin(), $db)->backupOwnTables();
        } catch (\Throwable) {
            // Deliberately swallowed: a failed self-backup must never block the
            // plugin's own update from proceeding.
        }
    }

    public function updated($oldVersion, $newVersion)
    {
        // Nothing to do — Migrations/* already ran automatically (see installed()).
    }
}
