<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

/**
 * Adds this plugin's own settings table and retires the native
 * `<Settingslink>` form entirely (see info.xml's own comment on the removed
 * block, and Controller\SettingsPageController's docblock, for the full
 * why): CONFIRMED against includes/src/Plugin/Admin/Installation/Items/
 * SettingsLinks.php::install() that a `<Settingslink>` can never register
 * its `<Setting>` schema without ALSO unconditionally creating a visible
 * `tpluginadminmenu` row — there's no way to keep the schema (which the
 * custom "Einstellungen" tab depends on) without also keeping the
 * "Erweiterte Einstellungen (Rohformular)" fallback tab. Moving storage to
 * a plugin-owned table removes that constraint entirely.
 *
 * The second statement below is a ONE-TIME, idempotent carry-over of
 * whatever an existing real install already saved through the native form
 * (confirmed such an install exists — this must not silently lose an
 * already-configured FTP host/password on update). `ON DUPLICATE KEY
 * UPDATE` makes re-running this safe; on a fresh install the SELECT simply
 * returns zero rows. The copied values need no format conversion — this
 * table stores encrypted fields in the EXACT SAME base64(XTEA(...)) shape
 * `tplugineinstellungen.cWert` already used (see SettingsRepository's own
 * docblock), so they decrypt correctly immediately after the copy.
 */
final class Migration20260827140000 extends Migration implements IMigration
{
    public function getAuthor(): string
    {
        return 'Sirko Wolfram';
    }

    public function getDescription(): string
    {
        return 'Add plugin-owned settings table and carry over any existing native Settingslink values';
    }

    public function up(): void
    {
        $this->execute(
            'CREATE TABLE IF NOT EXISTS `xplugin_jtl_dbbackup_tool_settings` (
                `cName` varchar(191) NOT NULL,
                `cValue` mediumtext NULL,
                PRIMARY KEY (`cName`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->execute(
            'INSERT INTO `xplugin_jtl_dbbackup_tool_settings` (`cName`, `cValue`) '
            . 'SELECT ti.cName, ti.cWert FROM tplugineinstellungen ti '
            . "JOIN tplugin p ON p.kPlugin = ti.kPlugin AND p.cPluginID = 'jtl_dbbackup_tool' "
            . 'ON DUPLICATE KEY UPDATE cValue = VALUES(cValue)',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `xplugin_jtl_dbbackup_tool_settings`');
    }
}
