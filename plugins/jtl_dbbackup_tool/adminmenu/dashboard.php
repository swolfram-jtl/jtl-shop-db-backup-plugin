<?php

declare(strict_types=1);

/**
 * Adminmenu <Customlink> entry point for the "Dashboard" tab (info.xml).
 *
 * Verified against PluginController::renderMenu() (release/5.8.0, GitLab
 * project 12536930): this file is require()'d inline with $oPlugin
 * (JTL\Plugin\PluginInterface) and $smarty (JTL\Smarty\JTLSmarty) already
 * declared in the calling scope, output-buffered into the tab's HTML — do
 * NOT redeclare either variable. The physical location
 * plugins/<PluginID>/adminmenu/dashboard.php is required (getAdminPath()
 * appends the fixed PFAD_PLUGIN_ADMINMENU = 'adminmenu/'); <Filename> in
 * info.xml stays relative to that folder ("dashboard.php", no prefix).
 * DB access goes through Shop::Container()->getDB(), not $this (technically
 * reachable via the enclosing method's scope, but not a documented API).
 */

use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Controller\DashboardController;

$controller = new DashboardController();
echo $controller->render($oPlugin, $smarty, Shop::Container()->getDB());
