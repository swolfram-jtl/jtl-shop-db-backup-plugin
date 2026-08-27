<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;
use JTL\Shop;
use Plugin\jtl_dbbackup_tool\Service\Upload\FtpsUploadTarget;
use Plugin\jtl_dbbackup_tool\Service\Upload\SftpUploadTarget;
use Plugin\jtl_dbbackup_tool\Service\Upload\UploadTargetInterface;

/**
 * Reads this plugin's own settings table (SettingsStore) — NOT
 * $plugin->getConfig(), a deliberate move away from the native
 * <Settingslink>/tplugineinstellungen* mechanism (see
 * Controller\SettingsPageController's own docblock, and
 * Migration20260827140000's, for the full why: that native mechanism can't
 * register its <Setting> schema without ALSO creating a permanent, visible
 * "Erweiterte Einstellungen (Rohformular)" menu tab, confirmed against
 * SettingsLinks::install()).
 *
 * Encrypted fields need one extra step this class still owns exactly like
 * before: values are stored as base64(XTEA(plaintext)) — the SAME shape
 * JTL\Plugin\Data\Config::getDecryptedValue()/PluginController::
 * handleEncryptedInput() already used natively (CONFIRMED against both),
 * reusing the shop's own CryptoServiceInterface (Shop::Container()->
 * getCryptoService()) rather than inventing new key management. This also
 * means the one-time-migrated values from an existing native install
 * decrypt correctly without any format conversion — see the migration's
 * docblock.
 *
 * CONFIRMED against admin/templates/bootstrap/tpl_inc/plugin_options.tpl and
 * Router\Controller\Backend\PluginController::actionConfig(): a checkbox
 * <Setting> has no real Y/N convention at all. The core's own checkbox
 * <input> carries no value= attribute, so a checked box always POSTs the
 * literal string "on" (the browser's default), and the settings template
 * only re-checks the box if the stored value === "on". An unchecked box
 * submits nothing, so the stored value becomes NULL, not "N". Earlier
 * assumed 'Y'/'N' here (wrong) — that's why every checkbox toggle in this
 * plugin silently read as permanently disabled even when info.xml declared
 * initialValue="Y" and the admin UI showed it checked. Controller\
 * SettingsPageController's own save logic (the only writer) still follows
 * this exact same convention.
 */
final class SettingsRepository
{
    private readonly SettingsStore $store;

    public function __construct(DbInterface $db)
    {
        $this->store = new SettingsStore($db);
    }

    private function value(string $name): ?string
    {
        $v = $this->store->get($name);

        return $v !== null && $v !== '' ? $v : null;
    }

    private function decrypted(string $name): ?string
    {
        $stored = $this->store->get($name);
        if ($stored === null || $stored === '') {
            return null;
        }

        $decoded = \base64_decode($stored, true);
        if ($decoded === false) {
            return null;
        }

        try {
            $plain = \rtrim(Shop::Container()->getCryptoService()->decryptXTEA($decoded));

            return $plain !== '' ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * $default applies only when the setting has NEVER been saved at all
     * (fresh install, nothing in the table yet) — a POSTED "off" still
     * always reads back as off, never silently reverts to $default. This
     * replaces what the removed native `<Setting initialValue="on">`
     * mechanism used to do (SettingsLinks::install() seeded that literal
     * value into the DB at install time); a fresh install of the plugin-
     * owned table starts genuinely empty, with no equivalent seeding step,
     * so every checkbox's real default now has to live here instead —
     * CONFIRMED as a real regression after an actual uninstall+reinstall
     * showed every checkbox unchecked, including ones that must default to
     * ON for their own safety net to apply (e.g. the pre-restore snapshot).
     */
    private function checkbox(string $name, bool $default = false): bool
    {
        // Deliberately checks presence (SettingsStore::has()), not
        // value()===null: an unchecked box is stored as an explicit NULL
        // (see SettingsPageController::persist()), which is indistinguishable
        // from "no row at all" via value() alone — that would make $default
        // silently override an admin's own explicit "off" the moment they'd
        // ever saved the form even once. has() only says "never saved".
        if (!$this->store->has($name)) {
            return $default;
        }

        return $this->value($name) === 'on';
    }

    public function encryptionEnabled(): bool
    {
        return $this->checkbox('encryption_enabled');
    }

    public function encryptionPassphrase(): ?string
    {
        return $this->decrypted('encryption_passphrase');
    }

    public function maintenanceModeEnabled(): bool
    {
        return $this->checkbox('maintenance_mode_enabled', true);
    }

    public function preRestoreSnapshotEnabled(): bool
    {
        return $this->checkbox('pre_restore_snapshot_enabled', true);
    }

    public function postRestoreConsistencyCheckEnabled(): bool
    {
        return $this->checkbox('post_restore_consistency_check_enabled', true);
    }

    public function versionFingerprintBlockEnabled(): bool
    {
        return $this->checkbox('version_fingerprint_block_enabled', true);
    }

    public function retentionMaxCount(): int
    {
        return (int) ($this->value('retention_max_count') ?? '15');
    }

    public function retentionMaxAgeDays(): int
    {
        return (int) ($this->value('retention_max_age_days') ?? '90');
    }

    public function notifyEmailOnFailure(): ?string
    {
        return $this->value('notify_email_on_failure');
    }

    public function hasFtpConfigured(): bool
    {
        return $this->value('ftp_host') !== null;
    }

    /**
     * Spec decision "Cronjob konfigurierbar": which presets the recurring
     * cron job backs up — see Cron/BackupCronJob.php. Falls back to every
     * PresetRegistry key if this was NEVER saved at all (matches this
     * plugin's previous, hardcoded-always-all-presets behavior, so an
     * upgrade never silently changes what an existing install's cron job
     * does). Deliberately checks presence (has()), not just an empty
     * result — same reasoning as checkbox()'s own docblock: an admin who
     * saves the form with every preset checkbox deliberately unchecked
     * (e.g. only wants the separate "Komplett"-only cron job type) posts an
     * empty string, which must stay an empty list, not silently revert to
     * "every preset" just because the value happens to be falsy.
     *
     * @return string[]
     */
    public function cronBackupPresets(): array
    {
        if (!$this->store->has('cron_backup_presets')) {
            return \array_keys(PresetRegistry::all());
        }

        $raw = $this->store->get('cron_backup_presets') ?? '';

        return \array_values(\array_filter(\array_map('trim', \explode(',', $raw))));
    }

    public function cronBackupIncludeFull(): bool
    {
        return $this->checkbox('cron_backup_include_full');
    }

    /**
     * Display-only summary for the "wo werden Backups abgelegt?" info box —
     * deliberately excludes credentials, only host/protocol/path.
     *
     * @return array{protocol: string, host: string, remoteDir: string}|null
     */
    public function ftpSummary(): ?array
    {
        if (!$this->hasFtpConfigured()) {
            return null;
        }

        return [
            'protocol'  => \strtoupper($this->value('ftp_protocol') ?? 'ftps'),
            'host'      => $this->value('ftp_host') ?? '',
            'remoteDir' => $this->value('ftp_remote_dir') ?? '/',
        ];
    }

    /**
     * @param array{host?: string, protocol?: string, port?: string, username?: string,
     *              password?: string, remoteDir?: string} $ephemeralOverride
     *        spec "Ephemere Zugangsdaten": when non-empty, REPLACES the stored
     *        settings entirely for this one run (never persisted) — it does
     *        not merely fill in gaps. A non-empty array here always means
     *        "ephemeral mode is active", even if a stored ftp_host also
     *        happens to exist; the two must never merge, since the whole
     *        point is a target that's independent of what's saved.
     */
    public function buildUploadTarget(array $ephemeralOverride = []): ?UploadTargetInterface
    {
        $ephemeral = $ephemeralOverride !== [];

        if (!$ephemeral && !$this->hasFtpConfigured()) {
            return null;
        }

        $protocol = $ephemeral ? ($ephemeralOverride['protocol'] ?? 'ftps') : ($this->value('ftp_protocol') ?? 'ftps');
        $host = $ephemeral ? ($ephemeralOverride['host'] ?? '') : ($this->value('ftp_host') ?? '');
        $port = (int) ($ephemeral
            ? ($ephemeralOverride['port'] ?? ($protocol === 'sftp' ? '22' : '21'))
            : ($this->value('ftp_port') ?? ($protocol === 'sftp' ? '22' : '21')));
        $username = $ephemeral ? ($ephemeralOverride['username'] ?? '') : ($this->value('ftp_username') ?? '');
        $remoteDir = $ephemeral ? ($ephemeralOverride['remoteDir'] ?? '/') : ($this->value('ftp_remote_dir') ?? '/');

        $password = $ephemeral ? ($ephemeralOverride['password'] ?? null) : $this->decrypted('ftp_password');
        $privateKey = $ephemeral ? null : $this->decrypted('ftp_private_key');
        $privateKeyPassphrase = $ephemeral ? null : $this->decrypted('ftp_private_key_passphrase');

        if ($host === '') {
            return null;
        }

        return $protocol === 'sftp'
            ? new SftpUploadTarget($host, $port, $username, $password, $privateKey, $privateKeyPassphrase, $remoteDir)
            : new FtpsUploadTarget($host, $port, $username, $password ?? '', $remoteDir);
    }

    /**
     * Builds an upload target straight from a just-submitted settings-form
     * POST, for the settings tab's "Speichern und Verbindung testen".
     *
     * Why this exists instead of just calling buildUploadTarget() again
     * after a save: JTL\Plugin\Data\Config loads all its values into an
     * in-memory Collection ONCE (Config::load(), confirmed against the real
     * core source) — typically before actionConfig() writes the fresh save
     * to the DB within the same request. Re-reading via $plugin->getConfig()
     * right after a same-request save can therefore still return the OLD
     * values. Reading straight from $_POST sidesteps the question entirely:
     * it's exactly what was just submitted, no cache staleness possible.
     * The one exception is the password/key fields, which follow the same
     * "blank = keep the existing stored value" convention as the real save
     * (PluginController::handleEncryptedInput()) — a blank field here falls
     * back to the last SAVED decrypted value instead of testing with an
     * empty credential.
     *
     * @param array<string, mixed> $postValues raw $_POST, keyed by <ValueName>
     */
    public function buildUploadTargetFromRequest(array $postValues): ?UploadTargetInterface
    {
        $protocol = (string) ($postValues['ftp_protocol'] ?? 'ftps');
        $host = \trim((string) ($postValues['ftp_host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $postedPort = \trim((string) ($postValues['ftp_port'] ?? ''));
        $port = (int) ($postedPort !== '' ? $postedPort : ($protocol === 'sftp' ? '22' : '21'));
        $username = (string) ($postValues['ftp_username'] ?? '');
        $remoteDir = (string) ($postValues['ftp_remote_dir'] ?? '/');

        $postedPassword = (string) ($postValues['ftp_password'] ?? '');
        $password = $postedPassword !== '' ? $postedPassword : $this->decrypted('ftp_password');

        $postedPrivateKey = (string) ($postValues['ftp_private_key'] ?? '');
        $privateKey = $postedPrivateKey !== '' ? $postedPrivateKey : $this->decrypted('ftp_private_key');

        $postedPassphrase = (string) ($postValues['ftp_private_key_passphrase'] ?? '');
        $privateKeyPassphrase = $postedPassphrase !== '' ? $postedPassphrase : $this->decrypted('ftp_private_key_passphrase');

        return $protocol === 'sftp'
            ? new SftpUploadTarget($host, $port, $username, $password, $privateKey, $privateKeyPassphrase, $remoteDir)
            : new FtpsUploadTarget($host, $port, $username, $password ?? '', $remoteDir);
    }
}
