<?php

declare(strict_types=1);

/**
 * Adminmenu <Customlink> entry point for the "Historie & Restore" tab
 * (info.xml). See dashboard.php for the verified runtime-context details
 * ($oPlugin, $smarty already in scope; physical path must be
 * adminmenu/history.php).
 */

use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Controller\HistoryController;

echo (new HistoryController())->render($oPlugin, $smarty, Shop::Container()->getDB());
