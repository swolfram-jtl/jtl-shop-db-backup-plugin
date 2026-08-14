<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Cron;

use JTL\Cron\Job;
use JTL\Cron\JobInterface;
use JTL\Cron\QueueEntry;
use JTL\Plugin\Helper;
use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Service\BackupTrigger;
use Plugin\jtl_dbbackup_tool\Service\PresetRegistry;

/**
 * Spec decision "Scheduling": optional cron backup, independent of the manual
 * button — this is the RECURRING scheduled case (registered via Bootstrap's
 * GET_AVAILABLE_CRONJOBS/MAP_CRONJOB_TYPE listeners). It always backs up
 * every preset table plus "full" is NOT run automatically — a recurring
 * "Komplett" is left to the admin to schedule deliberately via a large
 * enough interval, since it's the one preset that can meaningfully affect
 * performance.
 *
 * NOT independently verified: how a plugin obtains its own PluginInterface
 * instance from OUTSIDE the admin-menu request context (Cron\Queue runs
 * this class directly, without the $oPlugin the Customlink files get for
 * free). JTL\Plugin\Helper::getLoaderByPluginID()->init() is the best-known
 * pattern for this but wasn't confirmed against this specific repo/version
 * — if this breaks, the manual "Backup jetzt" flow (Controller/*) is
 * entirely unaffected, since it gets $oPlugin handed to it directly by the
 * framework and never goes through this class.
 */
final class BackupCronJob extends Job implements JobInterface
{
    private const PLUGIN_ID = 'jtl_dbbackup_tool';

    public function start(QueueEntry $queueEntry): JobInterface
    {
        try {
            $plugin = Helper::getLoaderByPluginID(self::PLUGIN_ID)?->init(self::PLUGIN_ID);
            if ($plugin === null) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Plugin-Instanz konnte im Cron-Kontext nicht geladen werden.'),
                );
            }

            $db = Shop::Container()->getDB();
            $trigger = new BackupTrigger($plugin, $db);

            foreach (\array_keys(PresetRegistry::all()) as $presetKey) {
                $trigger->trigger($presetKey, 0);
            }
        } catch (\Throwable) {
            // Swallowed deliberately: a cron job must never fatal the shop's
            // whole cron queue run over one plugin's failure. Failures are
            // still recorded per-preset in the backup history table by
            // BackupTrigger/BackupService, and trigger the failure e-mail.
        }

        return $this;
    }
}
