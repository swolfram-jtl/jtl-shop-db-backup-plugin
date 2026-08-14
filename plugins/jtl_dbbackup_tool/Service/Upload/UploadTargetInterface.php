<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service\Upload;

/**
 * Spec decision "Nur FTPS/SFTP": both implementations must refuse a plaintext
 * FTP connection outright rather than silently falling back to it.
 */
interface UploadTargetInterface
{
    /**
     * @throws \RuntimeException on connection/auth/write failure — callers
     *         must NOT let this fatal the whole backup run (spec: "Upload-
     *         Fehlerfall" — the local backup already succeeded and stays
     *         valid even if only the remote copy failed).
     */
    public function upload(string $localFilePath, string $remoteFileName): void;

    /**
     * Spec decision "Verbindungstest": checks login and write permission
     * without actually uploading a backup.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
