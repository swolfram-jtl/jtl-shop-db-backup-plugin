<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service\Upload;

/**
 * SFTP (SSH File Transfer Protocol) needs a real, audited SSH implementation
 * — this deliberately does NOT hand-roll SSH/SFTP protocol handling. It uses
 * phpseclib3\Net\SFTP, declared as a normal dependency in this plugin's own
 * composer.json (see plugins/jtl_dbbackup_tool/composer.json). Because
 * JTL-Shop plugins share the core's single Composer classmap and have no
 * isolated per-plugin vendor/ (verified — see docs/architecture-spec.html,
 * "Dependency-Isolation-Risiko"), this plugin ships its OWN vendor/ populated
 * by running `composer install` inside plugins/jtl_dbbackup_tool/ — see
 * README "Setup". If that hasn't been done, SFTP targets fail fast with a
 * clear message rather than a raw "class not found" fatal error.
 *
 * Supports either password or private-key authentication (spec: "Credential-
 * Verschlüsselung" — SFTP-Key-Auth as an alternative to a stored password).
 */
final class SftpUploadTarget implements UploadTargetInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly ?string $password,
        private readonly ?string $privateKey,
        private readonly ?string $privateKeyPassphrase,
        private readonly string $remoteDir,
    ) {
    }

    private function assertLibraryAvailable(): void
    {
        if (!\class_exists(\phpseclib3\Net\SFTP::class)) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'SFTP-Unterstützung benötigt phpseclib3, das noch nicht installiert ist. '
                . 'Bitte "composer install" im Plugin-Ordner (plugins/jtl_dbbackup_tool/) ausführen — siehe README.',
            ));
        }
    }

    public function upload(string $localFilePath, string $remoteFileName): void
    {
        $sftp = $this->connect();

        $remotePath = \rtrim($this->remoteDir, '/') . '/' . $remoteFileName;
        $tmpRemote = $remotePath . '.uploading';

        if (!$sftp->put($tmpRemote, $localFilePath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'SFTP-Upload fehlgeschlagen: %s', $remoteFileName),
            );
        }

        if (!$sftp->rename($tmpRemote, $remotePath)) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'SFTP: Konnte hochgeladene Datei nicht umbenennen: %s',
                $remoteFileName,
            ));
        }
    }

    public function testConnection(): array
    {
        try {
            $this->assertLibraryAvailable();
            $sftp = $this->connect();

            $remoteProbe = \rtrim($this->remoteDir, '/') . '/.dbbackup_connection_test';
            $writeOk = $sftp->put($remoteProbe, 'connection-test');
            if ($writeOk) {
                $sftp->delete($remoteProbe);
            }

            return $writeOk
                ? ['ok' => true, 'message' => \d__(
                    'jtl_dbbackup_tool',
                    'Verbindung erfolgreich, Schreibrechte im Zielverzeichnis bestätigt.',
                )]
                : ['ok' => false, 'message' => \d__(
                    'jtl_dbbackup_tool',
                    'Verbindung ok, aber Schreibtest im Zielverzeichnis fehlgeschlagen.',
                )];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function connect(): \phpseclib3\Net\SFTP
    {
        $this->assertLibraryAvailable();

        $sftp = new \phpseclib3\Net\SFTP($this->host, $this->port, 10);

        $authenticated = $this->privateKey !== null
            ? $sftp->login($this->username, \phpseclib3\Crypt\PublicKeyLoader::load(
                $this->privateKey,
                $this->privateKeyPassphrase ?? false,
            ))
            : $sftp->login($this->username, $this->password ?? '');

        if (!$authenticated) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'SFTP: Login fehlgeschlagen — Zugangsdaten/Key prüfen.'),
            );
        }

        return $sftp;
    }
}
