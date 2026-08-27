<?php

declare(strict_types=1);

/**
 * Adminmenu <Customlink> entry point for the "Einstellungen" tab (info.xml).
 * See dashboard.php for the verified runtime-context details ($oPlugin,
 * $smarty already in scope; physical path must be adminmenu/settings.php).
 */

use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Controller\SettingsPageController;

echo (new SettingsPageController())->render($oPlugin, $smarty, Shop::Container()->getDB());
