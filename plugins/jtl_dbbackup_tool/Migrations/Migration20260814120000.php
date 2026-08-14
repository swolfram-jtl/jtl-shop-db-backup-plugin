<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

/**
 * Verified against includes/src/Plugin/Migration.php,
 * includes/src/Plugin/Admin/Installation/MigrationManager.php, and a real
 * core migration example (Migration20260107104638.php) — plugin migrations
 * extend JTL\Plugin\Migration, only need to implement getAuthor()/
 * getDescription()/up()/down() (getId()/getName()/getCreated()/
 * doDeleteData()/setDeleteData() are already provided by the base class),
 * and run automatically — invoked by MigrationManager from Installer.php
 * during install/update, each inside its own transaction. No manual wiring
 * from Bootstrap.php needed.
 *
 * `implements IMigration` here is REQUIRED, not just documentation: neither
 * JTL\Update\Migration nor JTL\Plugin\Migration actually implement it
 * anywhere in their own class declaration (confirmed against source) — the
 * base class only implements JsonSerializable. MigrationManager checks
 * is_subclass_of($migration, IMigration::class) and throws
 * InvalidNamespaceException (surfaced to the admin as "Fehlercode 422 -
 * Ungültige Migration") if it's missing, since PHP's is_subclass_of() needs
 * an explicit interface declaration somewhere in the hierarchy.
 *
 * Creates this plugin's own tables — deliberately separate from anything the
 * backup/restore engine ever touches (spec: "Audit-Log-Integrität gegen die
 * eigene Restore-Funktion", see Service\PresetRegistry::ownTables()).
 */
final class Migration20260814120000 extends Migration implements IMigration
{
    public function getAuthor(): string
    {
        return 'Sirko Wolfram';
    }

    public function getDescription(): string
    {
        return 'Create audit log and backup history tables for jtl_dbbackup_tool';
    }

    public function up(): void
    {
        $this->execute(
            'CREATE TABLE IF NOT EXISTS `xplugin_jtl_dbbackup_tool_auditlog` (
                `kID` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `dCreated` datetime NOT NULL,
                `kAdminAccount` int(10) unsigned NOT NULL,
                `cAction` varchar(32) NOT NULL,
                `cScope` varchar(255) NOT NULL DEFAULT \'\',
                `cDetails` text NULL,
                PRIMARY KEY (`kID`),
                KEY `idx_dCreated` (`dCreated`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->execute(
            'CREATE TABLE IF NOT EXISTS `xplugin_jtl_dbbackup_tool_backuphistory` (
                `kID` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `dCreated` datetime NOT NULL,
                `cPresetKey` varchar(64) NOT NULL,
                `cLabel` varchar(255) NOT NULL DEFAULT \'\',
                `cFilename` varchar(255) NOT NULL,
                `cManifestFormatVersion` varchar(16) NOT NULL,
                `nSizeBytes` bigint(20) unsigned NOT NULL DEFAULT 0,
                `cStatus` varchar(32) NOT NULL,
                `cInstanceId` varchar(64) NOT NULL,
                `bEncrypted` tinyint(1) unsigned NOT NULL DEFAULT 0,
                `bUploaded` tinyint(1) unsigned NOT NULL DEFAULT 0,
                `cLastError` text NULL,
                PRIMARY KEY (`kID`),
                KEY `idx_dCreated` (`dCreated`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `xplugin_jtl_dbbackup_tool_auditlog`');
        $this->execute('DROP TABLE IF EXISTS `xplugin_jtl_dbbackup_tool_backuphistory`');
    }
}
