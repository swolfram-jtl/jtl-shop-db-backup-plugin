<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Cross-controller flash-message relay for the current request only.
 *
 * Fixes a real reported bug: every Adminmenu Customlink PHP file executes on
 * EVERY request (see RequestGuard's own docblock) to pre-render all tabs, so
 * a backup-trigger POST (`$_POST['preset']`) is visible to BOTH
 * BackupController AND DashboardController — whichever one's
 * RequestGuard::claimBackupTrigger() call happens to run first "wins" and
 * runs the trigger, with the result previously staying local to THAT
 * controller's own $flashMessage/$flashSuccess. Since execution order is an
 * implementation detail (which Customlink file Shop core happens to
 * require() first), not the tab the admin is actually looking at, a click on
 * "Backup jetzt" from the "Erstellen" tab could show its result on the
 * Dashboard tab instead — reported exactly as "Meldung erscheint im
 * Dashboard statt im Tab".
 *
 * Fix: whichever controller actually runs the trigger also pushes the result
 * here, and EVERY tab template renders the same message via
 * _partials/flash.tpl — so it shows up on whichever tab actually ends up
 * active after the reload (decided by the submitted cPluginTab field,
 * unrelated to this race), not just the one that happened to claim the
 * action first. A plain static property is enough: like RequestGuard's own
 * flags, it resets on every new HTTP request and stays set for the rest of
 * this one.
 */
final class FlashBus
{
    /** @var array{success: bool, text: string}|null */
    private static ?array $message = null;

    public static function set(bool $success, string $text): void
    {
        self::$message = ['success' => $success, 'text' => $text];
    }

    /**
     * @return array{success: bool, text: string}|null
     */
    public static function get(): ?array
    {
        return self::$message;
    }
}
