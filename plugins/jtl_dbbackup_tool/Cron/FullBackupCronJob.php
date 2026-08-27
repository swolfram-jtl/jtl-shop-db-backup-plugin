<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Cron;

use JTL\Cron\Job;
use JTL\Cron\JobInterface;
use JTL\Cron\QueueEntry;
use JTL\Plugin\Helper;
use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Service\BackupTrigger;

/**
 * A SECOND, independently schedulable cron job type dedicated to "Komplett"
 * (spec: an admin should be able to give the full backup its own schedule —
 * e.g. every preset nightly via BackupCronJob, "Komplett" only weekly via
 * this one — rather than only being able to fold it into the SAME job as
 * SettingsRepository::cronBackupIncludeFull() does). That existing "Komplett
 * im Cronjob einschließen" setting is untouched and still works for anyone
 * who wants a single combined job instead; this is a separate, additive
 * option, registered as its own job type (see Bootstrap::boot()) so JTL's
 * own Cron admin lets it be configured with its own interval/start time.
 *
 * Deliberately its own class rather than a shared base with BackupCronJob:
 * mirrors how core itself maps one job TYPE to one dedicated CLASS (see
 * JTL\Mapper\JobTypeToJob::map()) — the two classes' bodies are similar but
 * small, and a shared abstraction isn't worth it for two call sites.
 *
 * See BackupCronJob's own docblock for a real bug fixed here too:
 * Helper::getLoaderByPluginID() takes the NUMERIC kPlugin, not this class's
 * string PLUGIN_ID — Helper::getPluginById(string) is the correct method.
 *
 * See BackupCronJob's own docblock for a SECOND, more severe bug fixed here
 * too (identical root cause, this class had the same missing calls): never
 * calling `parent::start($queueEntry)` and never calling
 * `$this->setFinished(true)` meant this job's `tjobqueue` row was never
 * cleaned up, so it re-ran on every single pseudo-cron trigger regardless of
 * its configured interval, and `tcron.lastStart` never got recorded (why the
 * admin's Cron overview showed no "zuletzt gelaufen" time). Fixed the same
 * way, for the same reason.
 */
final class FullBackupCronJob extends Job implements JobInterface
{
    private const PLUGIN_ID = 'jtl_dbbackup_tool';

    public function start(QueueEntry $queueEntry): JobInterface
    {
        parent::start($queueEntry);
        $this->setFinished(true);

        try {
            $db = Shop::Container()->getDB();

            // See BackupCronJob's own docblock for why this is the best
            // available lever for a readable label — the raw type string
            // itself can't be translated from plugin-side.
            $db->update('tcron', 'cronID', $queueEntry->cronID, (object) [
                'name' => \d__('jtl_dbbackup_tool', 'DB Backup Manager – Komplettbackup'),
            ]);

            $plugin = Helper::getPluginById(self::PLUGIN_ID);
            if ($plugin === null) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Plugin-Instanz konnte im Cron-Kontext nicht geladen werden.'),
                );
            }

            (new BackupTrigger($plugin, $db))->trigger('full', 0, [
                'comment' => \d__('jtl_dbbackup_tool', 'Automatisches Backup'),
            ]);
        } catch (\Throwable) {
            // Swallowed deliberately: a cron job must never fatal the shop's
            // whole cron queue run over one plugin's failure. Failures are
            // still recorded in the backup history table by
            // BackupTrigger/BackupService, and trigger the failure e-mail.
        }

        return $this;
    }
}
