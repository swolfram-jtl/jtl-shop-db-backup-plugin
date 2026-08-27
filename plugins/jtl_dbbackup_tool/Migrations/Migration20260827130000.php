<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

/**
 * Adds the optional free-text `cComment` column backing the "DB Backup
 * Manager" tab's comment feature — deliberately separate from `cLabel`
 * (which stays the system-generated preset/type name, e.g. "Kundenimport"
 * or "Automatischer Vorab-Snapshot vor Restore") so a user note like "vor
 * Preis-Update Q3" never overwrites the auto-generated label.
 */
final class Migration20260827130000 extends Migration implements IMigration
{
    public function getAuthor(): string
    {
        return 'Sirko Wolfram';
    }

    public function getDescription(): string
    {
        return 'Add cComment column to backup history for the DB Backup Manager';
    }

    public function up(): void
    {
        $this->execute(
            'ALTER TABLE `xplugin_jtl_dbbackup_tool_backuphistory` '
            . 'ADD COLUMN `cComment` text NULL AFTER `cLabel`',
        );
    }

    public function down(): void
    {
        $this->execute(
            'ALTER TABLE `xplugin_jtl_dbbackup_tool_backuphistory` DROP COLUMN `cComment`',
        );
    }
}
