<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\DB\DbInterface;
use JTL\Helpers\Form;
use JTL\Plugin\PluginInterface;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_dbbackup_tool\Service\FlashBus;
use Plugin\jtl_dbbackup_tool\Service\PresetRegistry;
use Plugin\jtl_dbbackup_tool\Service\RequestGuard;

/**
 * Custom "Einstellungen" tab — replaces the native `<Settingslink>` rendering
 * (demoted to "Erweiterte Einstellungen (Rohformular)", sorted last — see
 * info.xml's comment on that Settingslink for why it can't be removed
 * outright) because that native form has two hard limitations, both
 * confirmed against the real core source and NOT fixable via info.xml alone:
 *   - `<Setting><Description>` always renders as a hover-only tooltip icon
 *     (admin/templates/bootstrap/tpl_inc/help_description.tpl), never
 *     always-visible text.
 *   - There is no hook for a plugin to inject its own JS/HTML into the
 *     auto-generated form (PluginController::renderMenu() fetches
 *     tpl_inc/plugin_options.tpl directly, no override/extension point) — so
 *     no conditional fields (e.g. "only show the encryption password once
 *     the checkbox is on") are possible there.
 *
 * This tab still uses the EXACT SAME persistence as the native form —
 * nothing about saving is reimplemented. `<Setting>` entries stay declared
 * under the (demoted) Settingslink purely for schema/defaults
 * (tplugineinstellungenconf/tplugineinstellungen — SettingsLinks::install()
 * seeds these at install time regardless of which admin UI edits them
 * afterward), and this tab's own <form> POSTs to the SAME page
 * (action="", like every other form in this plugin) with the same hidden
 * fields the native form sends: `Setting=1`, `kPluginAdminMenu` (looked up
 * at runtime below, never hardcoded — it's a DB-assigned ID), and
 * `jtl_token`. Confirmed in PluginController::getResponse():
 * `actionConfig($pluginID)` runs INSIDE the same request that renders this
 * very Customlink file whenever `Setting=1` is posted — before
 * renderMenu() — so by the time this controller's own render() runs, any
 * save from this exact submit has already happened and reads back fresh.
 * Every posted field uses `name="<ValueName>"` exactly like the native
 * form, so `SettingsRepository` (and this controller's own reads below)
 * need zero changes. Checkbox convention ("on"/absent, no "Y"/"N") and the
 * "empty encrypted field = keep existing value" behavior
 * (PluginController::handleEncryptedInput()) are both native `actionConfig()`
 * behavior this form relies on rather than reimplements.
 */
final class SettingsPageController
{
    public function render(PluginInterface $plugin, JTLSmarty $smarty, DbInterface $db): string
    {
        $flashMessage = null;
        $flashSuccess = true;
        $connectionTestResult = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['Setting'])) {
            // The actual settings save already ran inside
            // PluginController::getResponse() (actionConfig(), before this
            // Customlink file is even required()) — see this class's own
            // docblock. Nothing left to do here except show a confirmation;
            // core's own save always succeeds unless the CSRF token was
            // stale, which reads back as every field simply keeping its old
            // value (native behavior, not something this tab special-cases).
            $flashMessage = \d__('jtl_dbbackup_tool', 'Einstellungen gespeichert.');

            // Spec: "speichern und testen" oder nur "speichern", wie bei den
            // Mail-Server-Einstellungen — ONE form, two submit buttons
            // (settings.tpl's "test_connection" button carries name/value so
            // it only appears in $_POST when THAT button was actually
            // clicked). Always saves first (above), then optionally tests
            // with the just-submitted values — see SettingsController::
            // handleConnectionTest()'s docblock for why $_POST is passed
            // through rather than re-reading $plugin->getConfig().
            if (isset($_POST['test_connection']) && RequestGuard::claimTestConnectionAction()) {
                $connectionTestResult = (new SettingsController())->handleConnectionTest($plugin, $_POST);
            }
        }

        if ($flashMessage === null) {
            // See Service\FlashBus's docblock: picks up a backup-trigger
            // result from Dashboard/Erstellen when this tab is the one
            // actually active after the reload.
            $bus = FlashBus::get();
            if ($bus !== null) {
                $flashMessage = $bus['text'];
                $flashSuccess = $bus['success'];
            }
        }

        $config = $plugin->getConfig();
        $settingsLinkMenuId = $this->findSettingsLinkMenuId($plugin);

        $smarty->assign('tplDir', \dirname(__DIR__) . '/adminmenu/templates')
            ->assign('jtlToken', Form::getTokenInput())
            ->assign('kPlugin', $plugin->getID())
            ->assign('kPluginAdminMenu', $settingsLinkMenuId)
            ->assign('flashMessage', $flashMessage)
            ->assign('flashSuccess', $flashSuccess)
            ->assign('connectionTestResult', $connectionTestResult)
            ->assign('sections', $this->buildSections($config));

        return $smarty->fetch(\dirname(__DIR__) . '/adminmenu/templates/settings.tpl');
    }

    /**
     * Finds the (demoted) native Settingslink's DB-assigned menu ID — the
     * value the native form itself would post as `kPluginAdminMenu`.
     * `configurable === true` is how JTL-Shop's own AbstractLoader marks a
     * Settingslink-type menu item (confirmed: `$menu->configurable = (bool)
     * $menu->nConf` for this case vs. an explicit `false` for Customlink-type
     * items) — never hardcoded, since this ID is assigned fresh at install
     * time and would differ between installs.
     */
    private function findSettingsLinkMenuId(PluginInterface $plugin): int
    {
        foreach ($plugin->getAdminMenu()->getItems() as $menu) {
            if ($menu->configurable === true) {
                return (int) $menu->kPluginAdminMenu;
            }
        }

        return 0;
    }

    /**
     * @return array<int, array{title: string, fields: array<int, array<string, mixed>>}>
     */
    private function buildSections(\JTL\Plugin\Data\Config $config): array
    {
        $presetOptions = [];
        foreach (PresetRegistry::all() as $key => $preset) {
            $presetOptions[$key] = $preset['label'];
        }
        $selectedCronPresets = \array_filter(\explode(',', $this->raw($config, 'cron_backup_presets') ?? ''));

        return [
            [
                'title'  => \d__('jtl_dbbackup_tool', 'FTP/SFTP-Ziel'),
                'fields' => [
                    $this->select('ftp_protocol', \d__('jtl_dbbackup_tool', 'Übertragungsprotokoll'), $config,
                        ['ftps' => 'FTPS', 'sftp' => 'SFTP'],
                        \d__('jtl_dbbackup_tool', 'Klartext-FTP wird aus Sicherheitsgründen nicht unterstützt.')),
                    $this->text('ftp_host', \d__('jtl_dbbackup_tool', 'Host'), $config,
                        \d__('jtl_dbbackup_tool', 'Adresse des FTPS/SFTP-Servers, z. B. backup.example.com')),
                    $this->text('ftp_port', \d__('jtl_dbbackup_tool', 'Port'), $config,
                        \d__('jtl_dbbackup_tool', 'Leer lassen für den Standard-Port (FTPS: 21, SFTP: 22).')),
                    $this->text('ftp_username', \d__('jtl_dbbackup_tool', 'Benutzername'), $config, ''),
                    $this->encrypted('ftp_password', \d__('jtl_dbbackup_tool', 'Passwort'), $config,
                        \d__('jtl_dbbackup_tool', 'Für FTPS immer nötig; für SFTP nur, wenn kein privater Schlüssel hinterlegt ist.')),
                    $this->encrypted('ftp_private_key', \d__('jtl_dbbackup_tool', 'SFTP: Privater Schlüssel'), $config,
                        \d__('jtl_dbbackup_tool', 'Alternative zum Passwort, nur für SFTP. Einzeiliges Feld — den Schlüssel ggf. extern zu einer Zeile zusammenhängend kopieren.')),
                    $this->encrypted('ftp_private_key_passphrase', \d__('jtl_dbbackup_tool', 'SFTP: Passphrase des Schlüssels'), $config,
                        \d__('jtl_dbbackup_tool', 'Nur nötig, wenn der obige private Schlüssel selbst mit einer Passphrase geschützt ist.')),
                    $this->text('ftp_remote_dir', \d__('jtl_dbbackup_tool', 'Zielverzeichnis'), $config,
                        \d__('jtl_dbbackup_tool', 'Verzeichnis auf dem Server, in das Backups hochgeladen werden.')),
                ],
                'connectionTest' => true,
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Verschlüsselung'),
                'fields' => [
                    $this->checkbox('encryption_enabled', \d__('jtl_dbbackup_tool', 'Backup verschlüsseln'), $config,
                        \d__('jtl_dbbackup_tool', 'Verschlüsselt die Backup-Datei zusätzlich mit XChaCha20-Poly1305, bevor sie lokal gespeichert bzw. hochgeladen wird.'),
                        'encryption_passphrase'),
                    $this->encrypted('encryption_passphrase', \d__('jtl_dbbackup_tool', 'Verschlüsselungs-Passwort'), $config,
                        \d__('jtl_dbbackup_tool', 'Wichtig: dieses Passwort verloren = betroffene Backups unlesbar, es gibt keine Wiederherstellung ohne es.'),
                        'encryption_enabled'),
                ],
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Backup-Verhalten'),
                'fields' => [
                    $this->checkbox('maintenance_mode_enabled', \d__('jtl_dbbackup_tool', 'Wartungsmodus während des Backups'), $config,
                        \d__('jtl_dbbackup_tool', 'Reiner Kunden-Komfort (verhindert Bestellungen mitten im Backup) — keine technische Konsistenzgarantie für die Backup-Datei selbst.')),
                    $this->number('retention_max_count', \d__('jtl_dbbackup_tool', 'Max. Anzahl Backups'), $config,
                        \d__('jtl_dbbackup_tool', 'Ältere Backups werden automatisch gelöscht, sobald mehr als diese Anzahl vorhanden ist.')),
                    $this->number('retention_max_age_days', \d__('jtl_dbbackup_tool', 'Max. Alter in Tagen'), $config,
                        \d__('jtl_dbbackup_tool', 'Backups älter als diese Anzahl Tage werden automatisch gelöscht (0 = kein Limit).')),
                    $this->text('notify_email_on_failure', \d__('jtl_dbbackup_tool', 'Info-E-Mail bei Fehlschlag'), $config,
                        \d__('jtl_dbbackup_tool', 'E-Mail-Adresse für eine automatische Infomail, sobald ein Backup oder Upload fehlschlägt. Leer lassen für keine E-Mail.')),
                ],
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Wiederherstellen-Einstellungen'),
                'fields' => [
                    $this->checkbox('pre_restore_snapshot_enabled', \d__('jtl_dbbackup_tool', 'Vorab-Snapshot vor Restore'), $config,
                        \d__('jtl_dbbackup_tool', 'Legt vor jedem Restore automatisch ein Backup des aktuellen Stands an, damit ein missglückter Restore selbst rückgängig gemacht werden kann. Nur abschalten, wenn du genau weißt, warum.')),
                    $this->checkbox('post_restore_consistency_check_enabled', \d__('jtl_dbbackup_tool', 'Konsistenzprüfung nach Restore'), $config,
                        \d__('jtl_dbbackup_tool', 'Prüft nach einem Teil-Restore (z. B. nur Kunden) andere Tabellen auf möglicherweise verwaiste Datensätze und zeigt sie als Hinweis an.')),
                    $this->checkbox('version_fingerprint_block_enabled', \d__('jtl_dbbackup_tool', 'Bei Strukturabweichung blockieren'), $config,
                        \d__('jtl_dbbackup_tool', 'Blockiert Restore hart, wenn sich die Tabellenstruktur seit dem Backup geändert hat (z. B. durch ein Shop-Update), statt nur zu warnen. Ein Restore lässt sich dann nur mit ausdrücklicher Bestätigung erzwingen.')),
                ],
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Cronjob-Einstellungen'),
                'description' => \d__('jtl_dbbackup_tool', 'Legt fest, was der wiederkehrende Cronjob sichert — den Cronjob selbst richtest du in JTL-Shops eigener Cron-Verwaltung ein (siehe Hinweis im Dashboard).'),
                'fields' => [
                    [
                        'type'        => 'checkboxGroup',
                        'name'        => 'cron_backup_presets',
                        'label'       => \d__('jtl_dbbackup_tool', 'Presets im Cronjob'),
                        'description' => \d__('jtl_dbbackup_tool', 'Welche Presets der Cronjob bei jedem Lauf einzeln sichert.'),
                        'options'     => $presetOptions,
                        'selected'    => $selectedCronPresets,
                        'selectedCsv' => \implode(',', $selectedCronPresets),
                    ],
                    $this->checkbox('cron_backup_include_full', \d__('jtl_dbbackup_tool', '„Komplett" im Cronjob einschließen'), $config,
                        \d__('jtl_dbbackup_tool', 'Standard: aus — ein wiederkehrendes Komplett-Backup kann die Performance auf einer großen Datenbank spürbar beeinträchtigen.')),
                ],
            ],
        ];
    }

    private function raw(\JTL\Plugin\Data\Config $config, string $name): ?string
    {
        $v = $config->getValue($name);

        return \is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $name, string $label, \JTL\Plugin\Data\Config $config, string $description): array
    {
        return [
            'type' => 'text', 'name' => $name, 'label' => $label, 'description' => $description,
            'value' => $this->raw($config, $name) ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function number(string $name, string $label, \JTL\Plugin\Data\Config $config, string $description): array
    {
        return [
            'type' => 'number', 'name' => $name, 'label' => $label, 'description' => $description,
            'value' => $this->raw($config, $name) ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkbox(string $name, string $label, \JTL\Plugin\Data\Config $config, string $description, ?string $revealsField = null): array
    {
        return [
            'type' => 'checkbox', 'name' => $name, 'label' => $label, 'description' => $description,
            'checked' => $this->raw($config, $name) === 'on', 'revealsField' => $revealsField,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encrypted(string $name, string $label, \JTL\Plugin\Data\Config $config, string $description, ?string $revealedBy = null): array
    {
        return [
            'type' => 'encrypted', 'name' => $name, 'label' => $label, 'description' => $description,
            'hasValue' => $this->raw($config, $name) !== null, 'revealedBy' => $revealedBy,
        ];
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function select(string $name, string $label, \JTL\Plugin\Data\Config $config, array $options, string $description): array
    {
        return [
            'type' => 'select', 'name' => $name, 'label' => $label, 'description' => $description,
            'options' => $options, 'value' => $this->raw($config, $name) ?? \array_key_first($options),
        ];
    }
}
