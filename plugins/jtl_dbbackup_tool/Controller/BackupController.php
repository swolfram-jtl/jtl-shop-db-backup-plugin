<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_dbbackup_tool\Service\BackupTrigger;
use Plugin\jtl_dbbackup_tool\Service\PresetRegistry;
use Plugin\jtl_dbbackup_tool\Service\RequestGuard;

/**
 * Spec decision "Backup-Klick-Flow": one click starts a backup with the
 * configured defaults; an "Optionen für diesen Lauf" disclosure exposes
 * per-run overrides (destination, encryption, ephemeral credentials) without
 * blocking the common case behind a full dialog.
 * Spec decision "Preset-Benennung": preset labels must exactly match the
 * shop's own backend menu wording (see Service\PresetRegistry).
 */
final class BackupController
{
    public function render(PluginInterface $plugin, JTLSmarty $smarty, DbInterface $db): string
    {
        $flashMessage = null;
        $flashSuccess = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preset']) && RequestGuard::claimBackupTrigger()) {
            $adminAccountId = (int) ($_SESSION['AdminAccount']->kAdminlogin ?? 0);

            $formOptions = [
                'encrypt_override' => isset($_POST['encrypt_override']),
            ];

            if (isset($_POST['use_ephemeral_credentials']) && (string) ($_POST['eph_host'] ?? '') !== '') {
                $formOptions['use_ephemeral_credentials'] = true;
                $formOptions['ephemeral'] = [
                    'protocol' => (string) ($_POST['eph_protocol'] ?? 'ftps'),
                    'host'     => (string) $_POST['eph_host'],
                    'port'     => (string) ($_POST['eph_port'] ?? ''),
                    'username' => (string) ($_POST['eph_username'] ?? ''),
                    'password' => (string) ($_POST['eph_password'] ?? ''),
                ];
            }

            $result = (new BackupTrigger($plugin, $db))->trigger((string) $_POST['preset'], $adminAccountId, $formOptions);
            $flashMessage = $result['message'];
            $flashSuccess = $result['success'];
        }

        $presets = [];
        foreach (PresetRegistry::all() as $key => $preset) {
            $presets[$key] = $preset['label'];
        }

        $smarty->assign('tplDir', \dirname(__DIR__) . '/adminmenu/templates')
            ->assign('presets', $presets)
            ->assign('flashMessage', $flashMessage)
            ->assign('flashSuccess', $flashSuccess);

        return $smarty->fetch(\dirname(__DIR__) . '/adminmenu/templates/backup.tpl');
    }
}
