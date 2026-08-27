<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Controller;

use JTL\DB\DbInterface;
use JTL\Helpers\Form;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_dbbackup_tool\Service\FlashBus;
use Plugin\jtl_dbbackup_tool\Service\PresetRegistry;
use Plugin\jtl_dbbackup_tool\Service\RequestGuard;
use Plugin\jtl_dbbackup_tool\Service\SettingsRepository;
use Plugin\jtl_dbbackup_tool\Service\SettingsStore;

/**
 * Custom "Einstellungen" tab — fully self-contained persistence now (own
 * table via SettingsStore, own CSRF check, own save logic), no longer
 * reusing the native `<Settingslink>`/`actionConfig()` mechanism at all.
 *
 * WHY this changed from reusing native persistence (the original design) to
 * owning it outright: the native form was already replaced for rendering
 * (see below), but its `<Settingslink>` had to stay in info.xml purely to
 * keep the `<Setting>` schema registered, demoted to a sorted-last
 * "Erweiterte Einstellungen (Rohformular)" fallback tab — CONFIRMED against
 * includes/src/Plugin/Admin/Installation/Items/SettingsLinks.php::install()
 * that a `<Settingslink>` can NEVER register its `<Setting>` schema without
 * ALSO unconditionally creating a visible `tpluginadminmenu` row for it
 * (every `<Setting>` is installed as a child loop INSIDE the same foreach
 * that just inserted that menu row, using its ID) — there is no headless/
 * schema-only registration path in this shop version. Since that fallback
 * tab was explicitly unwanted ("kann weg"), the only way to actually remove
 * it was to stop depending on that schema at all: settings now live in this
 * plugin's own table (SettingsStore, see Migration20260827140000), and this
 * controller does its own CSRF check (Form::validateToken() — the native
 * form's equivalent check used to happen inside actionConfig(), which this
 * class no longer calls at all) and its own save logic (persist(), below),
 * deriving field types from the SAME $sections structure buildSections()
 * already returns for rendering — one source of truth for label/description/
 * type per field, not a second hardcoded list.
 *
 * Two hard limitations of the native form (unchanged from the original
 * design, still the reason a custom tab exists at all — confirmed against
 * admin/templates/bootstrap/tpl_inc/plugin_options.tpl and
 * help_description.tpl):
 *   - `<Setting><Description>` always renders as a hover-only tooltip icon,
 *     never always-visible text.
 *   - There is no hook for a plugin to inject its own JS/HTML into the
 *     auto-generated form — so no conditional fields (e.g. "only show the
 *     encryption password once its checkbox is on") are possible there.
 *
 * Encrypted fields (FTP password, SFTP key/passphrase, backup encryption
 * passphrase) are stored as base64(XTEA(plaintext)) via the shop's own
 * CryptoServiceInterface — the exact same shape/service the native
 * mechanism used, so an existing install's already-configured values
 * (carried over by Migration20260827140000) decrypt correctly with no
 * conversion, and no new key management is introduced. "Blank field keeps
 * the existing value" (the native convention on save) is preserved here.
 */
final class SettingsPageController
{
    public function render(JTLSmarty $smarty, DbInterface $db): string
    {
        $store = new SettingsStore($db);
        $settings = new SettingsRepository($db);
        $sections = $this->buildSections($store, $settings);

        $flashMessage = null;
        $flashSuccess = true;
        $connectionTestResult = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
            if (!Form::validateToken()) {
                $flashMessage = \d__('jtl_dbbackup_tool', 'Sicherheits-Token ungültig oder abgelaufen — bitte die Seite neu laden und erneut versuchen.');
                $flashSuccess = false;
            } else {
                $this->persist($sections, $_POST, $store);
                $flashMessage = \d__('jtl_dbbackup_tool', 'Einstellungen gespeichert.');
                // Re-read so the form reflects exactly what was just saved
                // (own table, read fresh right here — no cache-staleness
                // question the way $plugin->getConfig() had within a
                // single request, since nothing else caches this). Fresh
                // SettingsRepository too, since $store's own cache (which
                // it doesn't share with $settings' internal store anyway)
                // was already updated in place by persist().
                $settings = new SettingsRepository($db);
                $sections = $this->buildSections($store, $settings);

                // Spec: "speichern und testen" oder nur "speichern", wie bei
                // den Mail-Server-Einstellungen — settings.tpl's
                // "test_connection" button carries name/value so it only
                // appears in $_POST when THAT button was clicked. Always
                // saves first (above), then optionally tests with the
                // just-submitted values — see SettingsController::
                // handleConnectionTest()'s docblock for why $_POST is
                // passed through directly.
                if (isset($_POST['test_connection']) && RequestGuard::claimTestConnectionAction()) {
                    $connectionTestResult = (new SettingsController())->handleConnectionTest($db, $_POST);
                }
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

        $smarty->assign('tplDir', \dirname(__DIR__) . '/adminmenu/templates')
            ->assign('jtlToken', Form::getTokenInput())
            ->assign('flashMessage', $flashMessage)
            ->assign('flashSuccess', $flashSuccess)
            ->assign('connectionTestResult', $connectionTestResult)
            ->assign('sections', $sections);

        return $smarty->fetch(\dirname(__DIR__) . '/adminmenu/templates/settings.tpl');
    }

    /**
     * Derives everything it needs (field name + type) from the SAME
     * $sections structure buildSections() returns for rendering — see this
     * class's own docblock for why that matters (one source of truth,
     * rather than a second hardcoded field list that could drift out of
     * sync with the rendered form).
     *
     * @param array<int, array{title: string, fields: array<int, array<string, mixed>>}> $sections
     * @param array<string, mixed> $postValues raw $_POST
     */
    private function persist(array $sections, array $postValues, SettingsStore $store): void
    {
        $crypto = Shop::Container()->getCryptoService();

        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $name = $field['name'];

                if ($field['type'] === 'checkbox') {
                    $store->set($name, isset($postValues[$name]) ? 'on' : null);
                    continue;
                }

                if ($field['type'] === 'encrypted') {
                    $posted = \trim((string) ($postValues[$name] ?? ''));
                    if ($posted === '') {
                        // Native convention, preserved: a blank field on
                        // save means "keep the existing value", never wipes
                        // an already-stored secret just because the admin
                        // didn't retype it.
                        continue;
                    }
                    $store->set($name, \base64_encode($crypto->encryptXTEA($posted)));
                    continue;
                }

                // text / number / select / checkboxGroup — checkboxGroup
                // posts its already-comma-joined value via a hidden input
                // kept in sync by settings.tpl's own script, so it's a
                // plain string field here like any other.
                $store->set($name, \trim((string) ($postValues[$name] ?? '')));
            }
        }
    }

    /**
     * $settings (SettingsRepository, constructed from the SAME $store) is
     * used ONLY for the handful of fields whose default (when nothing has
     * ever been saved — a fresh install) must exactly match what the rest
     * of the plugin actually does at runtime: the checkboxes that default
     * to ON (pre-restore snapshot etc.) and the cron preset list, which
     * defaults to "every preset". Reading raw store values directly for
     * those would show a DIFFERENT default than SettingsRepository's own
     * getters use — exactly the bug reported after a real reinstall
     * ("alle Haken sind leer"): the plugin's own safety-net checkboxes
     * silently defaulting to off is a correctness issue, not just a
     * cosmetic one, so this has to be the single source of truth for both.
     *
     * @return array<int, array{title: string, fields: array<int, array<string, mixed>>}>
     */
    private function buildSections(SettingsStore $store, SettingsRepository $settings): array
    {
        $presetOptions = [];
        foreach (PresetRegistry::all() as $key => $preset) {
            $presetOptions[$key] = $preset['label'];
        }
        $selectedCronPresets = $settings->cronBackupPresets();

        return [
            [
                'title'  => \d__('jtl_dbbackup_tool', 'FTP/SFTP-Ziel'),
                'fields' => [
                    $this->select('ftp_protocol', \d__('jtl_dbbackup_tool', 'Übertragungsprotokoll'), $store,
                        ['ftps' => 'FTPS', 'sftp' => 'SFTP'],
                        \d__('jtl_dbbackup_tool', 'Klartext-FTP wird aus Sicherheitsgründen nicht unterstützt.')),
                    $this->text('ftp_host', \d__('jtl_dbbackup_tool', 'Host'), $store,
                        \d__('jtl_dbbackup_tool', 'Adresse des FTPS/SFTP-Servers, z. B. backup.example.com')),
                    $this->text('ftp_port', \d__('jtl_dbbackup_tool', 'Port'), $store,
                        \d__('jtl_dbbackup_tool', 'Leer lassen für den Standard-Port (FTPS: 21, SFTP: 22).')),
                    $this->text('ftp_username', \d__('jtl_dbbackup_tool', 'Benutzername'), $store, ''),
                    $this->encrypted('ftp_password', \d__('jtl_dbbackup_tool', 'Passwort'), $store,
                        \d__('jtl_dbbackup_tool', 'Für FTPS immer nötig; für SFTP nur, wenn kein privater Schlüssel hinterlegt ist.')),
                    $this->encrypted('ftp_private_key', \d__('jtl_dbbackup_tool', 'SFTP: Privater Schlüssel'), $store,
                        \d__('jtl_dbbackup_tool', 'Alternative zum Passwort, nur für SFTP. Einzeiliges Feld — den Schlüssel ggf. extern zu einer Zeile zusammenhängend kopieren.')),
                    $this->encrypted('ftp_private_key_passphrase', \d__('jtl_dbbackup_tool', 'SFTP: Passphrase des Schlüssels'), $store,
                        \d__('jtl_dbbackup_tool', 'Nur nötig, wenn der obige private Schlüssel selbst mit einer Passphrase geschützt ist.')),
                    $this->text('ftp_remote_dir', \d__('jtl_dbbackup_tool', 'Zielverzeichnis'), $store,
                        \d__('jtl_dbbackup_tool', 'Verzeichnis auf dem Server, in das Backups hochgeladen werden.'), '/'),
                ],
                'connectionTest' => true,
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Verschlüsselung'),
                'fields' => [
                    $this->checkbox('encryption_enabled', \d__('jtl_dbbackup_tool', 'Backup verschlüsseln'), $settings->encryptionEnabled(),
                        \d__('jtl_dbbackup_tool', 'Verschlüsselt die Backup-Datei zusätzlich mit XChaCha20-Poly1305, bevor sie lokal gespeichert bzw. hochgeladen wird.'),
                        'encryption_passphrase'),
                    $this->encrypted('encryption_passphrase', \d__('jtl_dbbackup_tool', 'Verschlüsselungs-Passwort'), $store,
                        \d__('jtl_dbbackup_tool', 'Wichtig: dieses Passwort verloren = betroffene Backups unlesbar, es gibt keine Wiederherstellung ohne es.'),
                        'encryption_enabled'),
                ],
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Backup-Verhalten'),
                'fields' => [
                    $this->checkbox('maintenance_mode_enabled', \d__('jtl_dbbackup_tool', 'Wartungsmodus während des Backups'), $settings->maintenanceModeEnabled(),
                        \d__('jtl_dbbackup_tool', 'Reiner Kunden-Komfort (verhindert Bestellungen mitten im Backup) — keine technische Konsistenzgarantie für die Backup-Datei selbst. Standard: an.')),
                    $this->number('retention_max_count', \d__('jtl_dbbackup_tool', 'Max. Anzahl Backups'), $store,
                        \d__('jtl_dbbackup_tool', 'Ältere Backups werden automatisch gelöscht, sobald mehr als diese Anzahl vorhanden ist.'), (string) $settings->retentionMaxCount()),
                    $this->number('retention_max_age_days', \d__('jtl_dbbackup_tool', 'Max. Alter in Tagen'), $store,
                        \d__('jtl_dbbackup_tool', 'Backups älter als diese Anzahl Tage werden automatisch gelöscht (0 = kein Limit).'), (string) $settings->retentionMaxAgeDays()),
                    $this->text('notify_email_on_failure', \d__('jtl_dbbackup_tool', 'Info-E-Mail bei Fehlschlag'), $store,
                        \d__('jtl_dbbackup_tool', 'E-Mail-Adresse für eine automatische Infomail, sobald ein Backup oder Upload fehlschlägt. Leer lassen für keine E-Mail.')),
                ],
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Wiederherstellen-Einstellungen'),
                'fields' => [
                    $this->checkbox('pre_restore_snapshot_enabled', \d__('jtl_dbbackup_tool', 'Vorab-Snapshot vor Restore'), $settings->preRestoreSnapshotEnabled(),
                        \d__('jtl_dbbackup_tool', 'Legt vor jedem Restore automatisch ein Backup des aktuellen Stands an, damit ein missglückter Restore selbst rückgängig gemacht werden kann. Standard: an — nur abschalten, wenn du genau weißt, warum.')),
                    $this->checkbox('post_restore_consistency_check_enabled', \d__('jtl_dbbackup_tool', 'Konsistenzprüfung nach Restore'), $settings->postRestoreConsistencyCheckEnabled(),
                        \d__('jtl_dbbackup_tool', 'Prüft nach einem Teil-Restore (z. B. nur Kunden) andere Tabellen auf möglicherweise verwaiste Datensätze und zeigt sie als Hinweis an. Standard: an.')),
                    $this->checkbox('version_fingerprint_block_enabled', \d__('jtl_dbbackup_tool', 'Bei Strukturabweichung blockieren'), $settings->versionFingerprintBlockEnabled(),
                        \d__('jtl_dbbackup_tool', 'Blockiert Restore hart, wenn sich die Tabellenstruktur seit dem Backup geändert hat (z. B. durch ein Shop-Update), statt nur zu warnen. Standard: an — ein Restore lässt sich dann nur mit ausdrücklicher Bestätigung erzwingen.')),
                ],
            ],
            [
                'title'  => \d__('jtl_dbbackup_tool', 'Cronjob-Einstellungen'),
                'description' => \d__('jtl_dbbackup_tool', 'Legt fest, was der erste, wiederkehrende Cronjob-Typ sichert — die Cronjobs selbst richtest du in JTL-Shops eigener Cron-Verwaltung ein (siehe Hinweis im Dashboard). Ein zweiter, unabhängiger Job-Typ sichert immer „Komplett", ohne dass er hier konfiguriert wird.'),
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
                    $this->checkbox('cron_backup_include_full', \d__('jtl_dbbackup_tool', '„Komplett" zusätzlich in DIESEN Cronjob einschließen'), $settings->cronBackupIncludeFull(),
                        \d__('jtl_dbbackup_tool', 'Standard: aus — ein wiederkehrendes Komplett-Backup kann die Performance auf einer großen Datenbank spürbar beeinträchtigen. Der zweite, unabhängige Cronjob-Typ für „Komplett" ist meist die bessere Wahl für ein eigenes Zeitfenster.')),
                ],
            ],
        ];
    }

    private function raw(SettingsStore $store, string $name): ?string
    {
        $v = $store->get($name);

        return $v !== null && $v !== '' ? $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $name, string $label, SettingsStore $store, string $description, string $default = ''): array
    {
        return [
            'type' => 'text', 'name' => $name, 'label' => $label, 'description' => $description,
            'value' => $this->raw($store, $name) ?? $default,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function number(string $name, string $label, SettingsStore $store, string $description, string $default = ''): array
    {
        return [
            'type' => 'number', 'name' => $name, 'label' => $label, 'description' => $description,
            'value' => $this->raw($store, $name) ?? $default,
        ];
    }

    /**
     * $checked is passed in already-resolved (from SettingsRepository, not
     * read raw here) — see buildSections()'s own docblock for why: several
     * of these checkboxes must default to ON when nothing has been saved
     * yet, and SettingsRepository is the single place that default lives,
     * shared with the code that actually acts on the setting at runtime.
     *
     * @return array<string, mixed>
     */
    private function checkbox(string $name, string $label, bool $checked, string $description, ?string $revealsField = null): array
    {
        return [
            'type' => 'checkbox', 'name' => $name, 'label' => $label, 'description' => $description,
            'checked' => $checked, 'revealsField' => $revealsField,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encrypted(string $name, string $label, SettingsStore $store, string $description, ?string $revealedBy = null): array
    {
        return [
            'type' => 'encrypted', 'name' => $name, 'label' => $label, 'description' => $description,
            'hasValue' => $this->raw($store, $name) !== null, 'revealedBy' => $revealedBy,
        ];
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function select(string $name, string $label, SettingsStore $store, array $options, string $description): array
    {
        return [
            'type' => 'select', 'name' => $name, 'label' => $label, 'description' => $description,
            'options' => $options, 'value' => $this->raw($store, $name) ?? \array_key_first($options),
        ];
    }
}
