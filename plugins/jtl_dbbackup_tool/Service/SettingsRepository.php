<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\Plugin\PluginInterface;
use Plugin\jtl_dbbackup_tool\Service\Upload\FtpsUploadTarget;
use Plugin\jtl_dbbackup_tool\Service\Upload\SftpUploadTarget;
use Plugin\jtl_dbbackup_tool\Service\Upload\UploadTargetInterface;

/**
 * Wraps JTL\Plugin\Data\Config, verified via includes/src/Plugin/Data/Config.php
 * and includes/src/Plugin/PluginInterface.php: a plugin reads its own settings
 * via $plugin->getConfig()->getValue($name), and — critically, for the
 * type="encrypted" fields declared in info.xml — MUST call
 * getDecryptedValue($name) instead to get real plaintext; getValue() on an
 * encrypted field returns the still-encrypted stored blob. This class is the
 * one place that distinction is applied, so callers never have to think
 * about it.
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
 * initialValue="Y" and the admin UI showed it checked.
 */
final class SettingsRepository
{
    public function __construct(private readonly PluginInterface $plugin)
    {
    }

    private function value(string $name): ?string
    {
        $v = $this->plugin->getConfig()->getValue($name);

        return \is_string($v) && $v !== '' ? $v : null;
    }

    private function decrypted(string $name): ?string
    {
        $v = $this->plugin->getConfig()->getDecryptedValue($name);

        return \is_string($v) && $v !== '' ? $v : null;
    }

    private function checkbox(string $name): bool
    {
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
        return $this->checkbox('maintenance_mode_enabled');
    }

    public function preRestoreSnapshotEnabled(): bool
    {
        return $this->checkbox('pre_restore_snapshot_enabled');
    }

    public function postRestoreConsistencyCheckEnabled(): bool
    {
        return $this->checkbox('post_restore_consistency_check_enabled');
    }

    public function versionFingerprintBlockEnabled(): bool
    {
        return $this->checkbox('version_fingerprint_block_enabled');
    }

    public function retentionMaxCount(): int
    {
        return (int) ($this->value('retention_max_count') ?? '10');
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
     * PresetRegistry key if unset (matches this plugin's previous,
     * hardcoded-always-all-presets behavior, so an upgrade never silently
     * changes what an existing install's cron job does).
     *
     * @return string[]
     */
    public function cronBackupPresets(): array
    {
        $raw = $this->value('cron_backup_presets');
        if ($raw === null) {
            return \array_keys(PresetRegistry::all());
        }

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
}
