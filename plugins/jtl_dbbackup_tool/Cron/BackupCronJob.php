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
 *
 * FIXED, a SECOND and more severe bug reported live ("es werden immer alle
 * backups angelegt, sobald diese pseudo-cron-grenze erreicht wird ... die
 * zeit letzter ausführung oder intervall wird offenbar nicht beachtet"):
 * this override never called `parent::start($queueEntry)` and never called
 * `$this->setFinished(true)`. CONFIRMED against includes/src/Cron/Queue.php
 * (`Queue::run()`) and every core job under includes/src/Cron/Job/*.php
 * (all of them call `parent::start()` first, then `setFinished()`):
 *   - `Job::start()` (the parent) is what writes `lastStart` into `tcron`
 *     and `tjobqueue` — skipping it means `tcron.lastStart` stays NULL
 *     forever, which is ALSO why the admin's Cron overview never showed a
 *     "zuletzt gelaufen" time for this job.
 *   - `Queue::run()` only calls `$job->delete()` (removes the row from
 *     `tjobqueue`) `if ($job->isFinished())`. `isFinished()` defaults to
 *     false and nothing here ever set it true, so the `tjobqueue` row this
 *     job's own trigger created was NEVER deleted. `Queue::loadQueueFromDB()`
 *     unconditionally reloads and RE-RUNS every row still sitting in
 *     `tjobqueue` on every single pseudo-cron trigger, regardless of
 *     `tcron.nextStart`/`frequency` — `Checker::check()` (which DOES respect
 *     nextStart) only ever decides whether to enqueue a NEW row in the first
 *     place; it does nothing to reap an already-enqueued one. That's exactly
 *     the "backup every ~2 minutes" symptom: once the row existed, it ran on
 *     every pseudo-cron pass forever, hard cost aside.
 * `setFinished(true)` here is deliberately unconditional and called BEFORE
 * the try/catch below (not conditioned on the backup's own success) — this
 * job type does all its work synchronously within a single start() call
 * (mirrors e.g. Job/RedirectCleanup.php, Job/TopSeller.php,
 * Job/VisitorCount.php, Job/GuaranteePdfCleanup.php: every "do the whole
 * thing in one pass" core job unconditionally finishes itself the same way).
 * A failed backup is still recorded via BackupTrigger/BackupService's own
 * history + notification handling; it must never leave the queue entry
 * "stuck" for the reasons above.
 */
final class BackupCronJob extends Job implements JobInterface
{
    private const PLUGIN_ID = 'jtl_dbbackup_tool';

    public function start(QueueEntry $queueEntry): JobInterface
    {
        parent::start($queueEntry);
        $this->setFinished(true);

        try {
            $db = Shop::Container()->getDB();

            // Spec "Titel muss lesbar sein": {__($job->getType())} in core's
            // own cron.tpl can only ever show this job's raw, untranslatable
            // type-string constant verbatim (CONFIRMED — see Bootstrap::
            // boot()'s own docblock: plain __() always resolves against the
            // ADMIN's own base.mo domain, which a plugin cannot register
            // strings into without touching core files). `tcron.name` is the
            // one column core itself renders right next to that raw type
            // string (cron.tpl: {if $job->getName() !== null}{$job->getName()}
            // {/if}) and actually lets a plugin supply something readable —
            // confirmed NOT NULL but always populated by core's own add-cron
            // form with a throwaway 'manuell@<timestamp>' value, safe to
            // overwrite. Kept to a single UPDATE by primary key, refreshed on
            // every real run (now infrequent again, see above).
            $db->update('tcron', 'cronID', $queueEntry->cronID, (object) [
                'name' => \d__('jtl_dbbackup_tool', 'DB Backup Manager – Standard-Backup'),
            ]);

            $plugin = Helper::getPluginById(self::PLUGIN_ID);
            if ($plugin === null) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Plugin-Instanz konnte im Cron-Kontext nicht geladen werden.'),
                );
            }

            $trigger = new BackupTrigger($plugin, $db);
            $settings = new SettingsRepository($db);

            // Spec: Backups, die vom Cronjob angelegt wurden, sind über einen
            // festen Kommentar erkennbar — landet sowohl in der DB
            // (BackupHistoryRepository::create()) als auch im Manifest-JSON
            // neben der Backup-Datei selbst (siehe ManifestService::build()).
            $formOptions = ['comment' => \d__('jtl_dbbackup_tool', 'Automatisches Backup')];

            foreach ($settings->cronBackupPresets() as $presetKey) {
                $trigger->trigger($presetKey, 0, $formOptions);
            }
            if ($settings->cronBackupIncludeFull()) {
                $trigger->trigger('full', 0, $formOptions);
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
