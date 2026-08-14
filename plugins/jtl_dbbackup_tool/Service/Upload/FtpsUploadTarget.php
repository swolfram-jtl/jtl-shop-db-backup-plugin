<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service\Upload;

/**
 * FTPS (FTP over explicit TLS) via PHP's native ext-ftp — already a required
 * extension in the shop core's own composer.json, so no extra dependency.
 * Deliberately uses ftp_ssl_connect() only; there is no plaintext ftp_connect()
 * fallback anywhere in this class (spec: "Nur FTPS/SFTP", a hard limit).
 */
final class FtpsUploadTarget implements UploadTargetInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $remoteDir,
    ) {
    }

    public function upload(string $localFilePath, string $remoteFileName): void
    {
        $conn = $this->connect();

        try {
            $remotePath = \rtrim($this->remoteDir, '/') . '/' . $remoteFileName;
            $tmpRemote = $remotePath . '.uploading';

            if (!\ftp_put($conn, $tmpRemote, $localFilePath, \FTP_BINARY)) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'FTPS-Upload fehlgeschlagen: %s', $remoteFileName),
                );
            }

            // Atomic-ish on the remote side too: rename only completes after a
            // full, successful transfer (mirrors the local .tmp-then-rename pattern).
            if (!@\ftp_rename($conn, $tmpRemote, $remotePath)) {
                throw new \RuntimeException(\d__(
                    'jtl_dbbackup_tool',
                    'FTPS: Konnte hochgeladene Datei nicht umbenennen: %s',
                    $remoteFileName,
                ));
            }
        } finally {
            \ftp_close($conn);
        }
    }

    public function testConnection(): array
    {
        try {
            $conn = $this->connect();
            $probeFile = \tempnam(\sys_get_temp_dir(), 'dbbackup_probe_');
            \file_put_contents($probeFile, 'connection-test');
            $remoteProbe = \rtrim($this->remoteDir, '/') . '/.dbbackup_connection_test';

            $writeOk = \ftp_put($conn, $remoteProbe, $probeFile, \FTP_BINARY);
            if ($writeOk) {
                @\ftp_delete($conn, $remoteProbe);
            }

            \unlink($probeFile);
            \ftp_close($conn);

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

    /** @return resource */
    private function connect()
    {
        $conn = \ftp_ssl_connect($this->host, $this->port, 10);
        if ($conn === false) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'FTPS: Verbindung zu %s:%d fehlgeschlagen.',
                $this->host,
                $this->port,
            ));
        }

        if (!\ftp_login($conn, $this->username, $this->password)) {
            \ftp_close($conn);

            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'FTPS: Login fehlgeschlagen — Zugangsdaten prüfen.'),
            );
        }

        \ftp_pasv($conn, true);

        return $conn;
    }
}
