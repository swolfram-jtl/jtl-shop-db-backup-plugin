<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;
use JTL\Shop;

/**
 * Spec decisions "Versions-/Struktur-Fingerprint-Block", "Format-
 * Abwärtskompatibilität", "Multi-Shop-Kollisionsschutz": every backup gets a
 * sidecar `<file>.manifest.json` recording enough to safely judge, at restore
 * time, whether the live schema still matches, and whether the backup came
 * from this exact shop instance.
 *
 * Uses JTL\DB\DbInterface::getObjects() against information_schema.columns —
 * a lightweight structural fingerprint (column names+types), NOT a real
 * schema-migration diff. "Best-effort", same as the rest of the consistency
 * tooling: core has no schema-version table plugins can read directly.
 */
final class ManifestService
{
    public const MANIFEST_FORMAT_VERSION = '1';

    public function __construct(private readonly DbInterface $db)
    {
    }

    /**
     * @param string[] $tables
     */
    public function build(array $tables, string $presetKey, bool $encrypted, ?string $comment = null): array
    {
        return [
            'formatVersion' => self::MANIFEST_FORMAT_VERSION,
            'createdAt'     => \date('c'),
            'shopVersion'   => \defined('APPLICATION_VERSION') ? \APPLICATION_VERSION : 'unknown',
            'instanceId'    => $this->instanceId(),
            'presetKey'     => $presetKey,
            'encrypted'     => $encrypted,
            'comment'       => $comment,
            'tables'        => $this->fingerprintTables($tables),
        ];
    }

    /**
     * Spec decision "Kommentare unabhängig nachvollziehbar": the comment
     * belongs in the manifest sidecar file, not only in this plugin's own DB
     * table — a table that's gone the moment the plugin is uninstalled (or
     * never existed yet, on a fresh install restoring an old backup set).
     * Keeps BackupHistoryRepository::updateComment() (the DB row, used for
     * fast listing/search/filter) and this file (the durable, plugin-
     * independent record) in sync on every edit from the Manager tab's
     * inline comment field. Best-effort/no-op if the manifest file itself is
     * missing (e.g. a very old backup predating manifests, or one already
     * cleaned up) — the DB write still succeeds either way.
     */
    public function updateComment(string $manifestPath, ?string $comment): void
    {
        $manifest = $this->load($manifestPath);
        if ($manifest === null) {
            return;
        }

        $manifest['comment'] = $comment;
        $this->save($manifest, $manifestPath);
    }

    public function load(string $manifestPath): ?array
    {
        if (!\file_exists($manifestPath)) {
            return null;
        }

        $decoded = \json_decode((string) \file_get_contents($manifestPath), true);

        return \is_array($decoded) ? $decoded : null;
    }

    public function save(array $manifest, string $manifestPath): void
    {
        \file_put_contents($manifestPath, \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return string[] human-readable warnings — empty if everything still matches
     */
    public function compareToLive(array $manifest): array
    {
        $warnings = [];

        if (($manifest['instanceId'] ?? null) !== $this->instanceId()) {
            $warnings[] = \d__(
                'jtl_dbbackup_tool',
                'Dieses Backup stammt von einer ANDEREN Shop-Instanz — Restore ist auf dieselbe Instanz beschränkt (v1).',
            );
        }

        $liveFingerprints = $this->fingerprintTables(\array_keys($manifest['tables'] ?? []));
        foreach ($manifest['tables'] ?? [] as $table => $storedFingerprint) {
            if (($liveFingerprints[$table] ?? null) !== $storedFingerprint) {
                $warnings[] = \d__(
                    'jtl_dbbackup_tool',
                    'Tabellenstruktur von `%s` weicht vom Backup-Zeitpunkt ab (Core-Update oder andere Migration seitdem).',
                    $table,
                );
            }
        }

        return $warnings;
    }

    /**
     * @param string[] $tables
     * @return array<string, string> table => sha256 structural fingerprint
     */
    private function fingerprintTables(array $tables): array
    {
        $fingerprints = [];
        foreach ($tables as $table) {
            $columns = $this->db->getObjects(
                'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.columns '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION',
                ['table' => $table],
            );

            if ($columns === []) {
                $fingerprints[$table] = 'MISSING';
                continue;
            }

            $signature = \implode('|', \array_map(
                static fn ($c) => $c->COLUMN_NAME . ':' . $c->COLUMN_TYPE,
                $columns,
            ));
            $fingerprints[$table] = \hash('sha256', $signature);
        }

        return $fingerprints;
    }

    /**
     * Identifier for this shop instance — used to stop a backup from one shop
     * being restored onto (or colliding with, on a shared FTP target) a
     * different one. Derived from the shop's own base URL: a genuine
     * instance move (different domain) is exactly the case where we'd WANT
     * this to change.
     *
     * NOT independently verified: exact static accessor name for the shop's
     * base URL (assumed Shop::getURL()) — guarded with method_exists() so a
     * wrong guess degrades to the static fallback below rather than a fatal
     * error. A per-process fallback (not persisted across requests) is a
     * known gap for the rare case Shop::getURL() isn't available — see
     * README "Known gaps"; writing a value back through JTL\Plugin\Data\Config
     * needs that class's write-side API confirmed first.
     */
    public function instanceId(): string
    {
        if (\class_exists(Shop::class) && \method_exists(Shop::class, 'getURL')) {
            $url = Shop::getURL();
            if (\is_string($url) && $url !== '') {
                return \hash('sha256', $url);
            }
        }

        return 'unknown-instance';
    }
}
