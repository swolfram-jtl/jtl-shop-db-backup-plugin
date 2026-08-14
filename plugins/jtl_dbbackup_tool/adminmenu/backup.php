<?php

declare(strict_types=1);

/**
 * Adminmenu <Customlink> entry point for the "Backup jetzt" tab (info.xml).
 * See dashboard.php for the verified runtime-context details ($oPlugin,
 * $smarty already in scope; physical path must be adminmenu/backup.php).
 */

use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Controller\BackupController;

echo (new BackupController())->render($oPlugin, $smarty, Shop::Container()->getDB());
