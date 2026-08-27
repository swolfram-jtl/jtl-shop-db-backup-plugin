<?php

declare(strict_types=1);

/**
 * Adminmenu <Customlink> entry point for the "Einstellungen" tab (info.xml).
 * See dashboard.php for the verified runtime-context details ($smarty
 * already in scope; physical path must be adminmenu/settings.php). Unlike
 * the other three Customlink entry points, $oPlugin is deliberately unused
 * here — see SettingsPageController's own docblock for why: settings now
 * live in this plugin's own table (SettingsStore), not $plugin->getConfig().
 */

use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Controller\SettingsPageController;

echo (new SettingsPageController())->render($smarty, Shop::Container()->getDB());
