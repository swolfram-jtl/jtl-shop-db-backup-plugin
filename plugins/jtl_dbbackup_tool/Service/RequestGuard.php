<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Confirmed against a real install: PluginController::renderMenu() executes
 * EVERY Adminmenu <Customlink> entry file on a single request (to pre-render
 * all tabs' HTML, not just the currently visible one) — so a form POSTed to
 * action="" (the current URL) is visible to every controller's render()
 * call, not just the one "owning" tab. Without this guard, clicking "Backup
 * jetzt" once triggered the backup twice (DashboardController's quick-access
 * handler AND BackupController's own handler both saw the same $_POST and
 * both ran it), creating two identical history rows per click.
 *
 * A plain static flag is sufficient: it resets on every new HTTP request
 * (each request is a fresh PHP process/script execution) but stays true for
 * the remainder of THIS request, regardless of how many Customlink files get
 * required() within it.
 */
final class RequestGuard
{
    private static bool $backupTriggered = false;
    private static bool $restoreActionHandled = false;
    private static bool $deleteActionHandled = false;
    private static bool $commentActionHandled = false;

    public static function claimBackupTrigger(): bool
    {
        if (self::$backupTriggered) {
            return false;
        }

        self::$backupTriggered = true;

        return true;
    }

    public static function claimRestoreAction(): bool
    {
        if (self::$restoreActionHandled) {
            return false;
        }

        self::$restoreActionHandled = true;

        return true;
    }

    public static function claimDeleteAction(): bool
    {
        if (self::$deleteActionHandled) {
            return false;
        }

        self::$deleteActionHandled = true;

        return true;
    }

    public static function claimCommentAction(): bool
    {
        if (self::$commentActionHandled) {
            return false;
        }

        self::$commentActionHandled = true;

        return true;
    }
}
