<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Cron;

use JTL\Cron\Job;
use JTL\Cron\JobInterface;
use JTL\Cron\QueueEntry;
use JTL\Plugin\Helper;
use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Service\BackupTrigger;
use Plugin\jtl_dbbackup_tool\Service\SettingsRepository;

/**
 * Spec decision "Scheduling": optional cron backup, independent of the manual
 * button — this is the RECURRING scheduled case (registered via Bootstrap's
 * GET_AVAILABLE_CRONJOBS/MAP_CRONJOB_TYPE listeners).
 * Spec decision "Cronjob konfigurierbar": which presets run, and whether
 * "Komplett" is also included, are both settings now (Einstellungen tab →
 * "Cronjob-Einstellungen" — see SettingsRepository::cronBackupPresets()/
 * cronBackupIncludeFull()) rather than hardcoded. Defaults preserve the
 * previous behavior exactly (every preset, never "Komplett") so upgrading
 * an existing install doesn't silently change what its cron job does.
 *
 * FIXED, a real bug that likely made every recurring run silently fail from
 * day one: this used to call `Helper::getLoaderByPluginID(self::PLUGIN_ID)`
 * — but that method's signature is `getLoaderByPluginID(int $id, ...)`, the
 * NUMERIC `kPlugin`, not the string PluginID this class only ever has. Under
 * this file's own `declare(strict_types=1)`, passing a string there throws
 * a `TypeError` immediately — caught by the blanket `catch (\Throwable)`
 * below, so the job just silently did nothing on every scheduled run,
 * indistinguishable from "ran, nothing to do". CONFIRMED against
 * includes/src/Plugin/Helper.php: `getPluginById(string $pluginID):
 * ?PluginInterface` is the correct method for exactly this case — takes the
 * string ID this class actually has, resolves the numeric `kPlugin` itself
 * (via a cached `tplugin` lookup), and returns an already-`init()`'d
 * PluginInterface directly, no separate loader step needed. The manual
 * "Backup jetzt" flow (Controller/*) was never affected either way, since it
 * gets `$oPlugin` handed to it directly by the framework and never goes
 * through this class.
 */
final class BackupCronJob extends Job implements JobInterface
{
    private const PLUGIN_ID = 'jtl_dbbackup_tool';

    public function start(QueueEntry $queueEntry): JobInterface
    {
        try {
            $plugin = Helper::getPluginById(self::PLUGIN_ID);
            if ($plugin === null) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Plugin-Instanz konnte im Cron-Kontext nicht geladen werden.'),
                );
            }

            $db = Shop::Container()->getDB();
            $trigger = new BackupTrigger($plugin, $db);
            $settings = new SettingsRepository($db);

            foreach ($settings->cronBackupPresets() as $presetKey) {
                $trigger->trigger($presetKey, 0);
            }
            if ($settings->cronBackupIncludeFull()) {
                $trigger->trigger('full', 0);
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
