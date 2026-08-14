<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\Plugin\PluginInterface;
use Plugin\jtl_dbbackup_tool\Service\SettingsRepository;

/**
 * The <Settingslink> fields themselves (info.xml) are rendered by core's own
 * plugin-settings machinery (JTL\Plugin\Data\Config) — no custom rendering
 * needed for those. This controller only covers the one interactive extra the
 * spec calls for beyond a plain settings form.
 *
 * Spec decision "Verbindungstest": a "Verbindung testen" action that checks
 * FTPS/SFTP login and write permissions immediately, rather than waiting for
 * the first real backup to reveal a misconfiguration.
 *
 * KNOWN GAP: how this hooks into the Settingslink-rendered form (an extra
 * button/AJAX endpoint alongside the auto-rendered fields) was not verified
 * — only the Customlink dispatch mechanism was confirmed against the core.
 * The actual connection-test LOGIC below is complete and independently
 * usable once that wiring is figured out.
 */
final class SettingsController
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function handleConnectionTest(PluginInterface $plugin): array
    {
        $settings = new SettingsRepository($plugin);
        $target = $settings->buildUploadTarget();

        if ($target === null) {
            return ['ok' => false, 'message' => \d__(
                'jtl_dbbackup_tool',
                'Kein FTP/SFTP-Ziel konfiguriert (Host-Feld ist leer).',
            )];
        }

        return $target->testConnection();
    }
}
