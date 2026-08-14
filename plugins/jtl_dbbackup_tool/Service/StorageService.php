<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Speicherort außerhalb des Webroots": backups live in a
 * dedicated directory one level above the plugin folder (which itself lives
 * under the shop's plugins/ directory, inside the webroot) — walking up to
 * the shop root and then into a sibling, non-webroot directory keeps this
 * working regardless of hosting layout, with a deny-all .htaccess as a
 * second line of defense for setups where that assumption doesn't quite hold.
 *
 * Spec decision "Atomares Schreiben": callers must write to path() + '.tmp'
 * and only call commit() once the write is complete and verified.
 */
final class StorageService
{
    private string $baseDir;

    public function __construct(string $pluginDir)
    {
        // pluginDir = plugins/<PluginID> inside the shop webroot. Go up to the
        // shop root, then use a sibling directory outside plugins/ entirely.
        $shopRoot = \dirname($pluginDir, 2);
        $this->baseDir = $shopRoot . '/jtl_dbbackup_tool_storage';

        $this->ensureBaseDir();
    }

    private function ensureBaseDir(): void
    {
        if (!\is_dir($this->baseDir)) {
            \mkdir($this->baseDir, 0750, true);
        }

        $htaccess = $this->baseDir . '/.htaccess';
        if (!\file_exists($htaccess)) {
            \file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
    }

    public function backupDirFor(string $instanceId): string
    {
        $dir = $this->baseDir . '/' . \preg_replace('/[^a-zA-Z0-9_-]/', '_', $instanceId);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /**
     * Spec decision "Multi-Shop-Kollisionsschutz": the instance identifier is
     * baked into every filename, and the directory check below warns if a
     * FOREIGN instance's backups are found where this instance expects only
     * its own — most likely a misconfigured shared FTP target.
     *
     * @return string[] warnings
     */
    public function checkForForeignInstanceFiles(string $dir, string $ownInstanceId): array
    {
        $warnings = [];
        foreach (\glob($dir . '/*.manifest.json') ?: [] as $manifestFile) {
            $manifest = \json_decode((string) \file_get_contents($manifestFile), true);
            $foundInstanceId = $manifest['instanceId'] ?? null;
            if ($foundInstanceId !== null && $foundInstanceId !== $ownInstanceId) {
                $warnings[] = \d__(
                    'jtl_dbbackup_tool',
                    'Verzeichnis enthält Backups einer anderen Shop-Instanz (%s) — bitte Zielordner prüfen.',
                    $foundInstanceId,
                );
            }
        }

        return $warnings;
    }

    public function freeDiskSpaceBytes(): float|false
    {
        // disk_free_space() returns float (large filesystems overflow a
        // 32-bit-safe int), not int — the earlier int|false declaration was
        // simply wrong and threw a TypeError on every real call.
        return \disk_free_space($this->baseDir);
    }

    public function baseDirectory(): string
    {
        return $this->baseDir;
    }

    /**
     * Atomic write helper: caller writes to the returned .tmp path, then calls
     * commit() — never write directly to $finalPath.
     */
    public function tmpPathFor(string $finalPath): string
    {
        return $finalPath . '.tmp';
    }

    public function commit(string $tmpPath, string $finalPath): void
    {
        if (!\rename($tmpPath, $finalPath)) {
            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'Konnte Backup-Datei nicht finalisieren: %s -> %s',
                $tmpPath,
                $finalPath,
            ));
        }
    }

    public function delete(string $path): void
    {
        if (\file_exists($path)) {
            \unlink($path);
        }
    }
}
