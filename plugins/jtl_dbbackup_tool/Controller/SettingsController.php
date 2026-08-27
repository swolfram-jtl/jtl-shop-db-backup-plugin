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
 * Wired into Controller\SettingsPageController's "Speichern und Verbindung
 * testen" submit button — a real reported bug ("Eingabe im Hostfeld wird
 * geleert und als Fehler ausgegeben") traced back to the previous design: a
 * SEPARATE form that posted only test_connection=1 with none of the actual
 * FTP fields, so a full page reload re-rendered the host field from whatever
 * was last SAVED (often nothing) rather than what was just typed. Fixed by
 * merging into the single settings form (always saves first) and passing
 * $_POST straight through here — see SettingsRepository::
 * buildUploadTargetFromRequest()'s docblock for why $_POST is used instead
 * of re-reading $plugin->getConfig() even after that save.
 */
final class SettingsController
{
    /**
     * @param array<string, mixed> $postValues raw $_POST from the settings
     *        form — pass [] to fall back to the currently SAVED config
     *        instead (used nowhere in this plugin's own UI anymore, kept for
     *        any future non-form caller).
     *
     * @return array{ok: bool, message: string}
     */
    public function handleConnectionTest(PluginInterface $plugin, array $postValues = []): array
    {
        $settings = new SettingsRepository($plugin);
        $target = $postValues !== []
            ? $settings->buildUploadTargetFromRequest($postValues)
            : $settings->buildUploadTarget();

        if ($target === null) {
            return ['ok' => false, 'message' => \d__(
                'jtl_dbbackup_tool',
                'Kein FTP/SFTP-Ziel konfiguriert (Host-Feld ist leer).',
            )];
        }

        return $target->testConnection();
    }
}
