<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;

/**
 * CRUD for this plugin's own backup-history table — the record that backs the
 * "Historie & Restore" tab and the Dashboard status tiles. Permanently
 * excluded from this plugin's own restore scope, same as AuditLogger's table
 * (spec: "Audit-Log-Integrität").
 */
final class BackupHistoryRepository
{
    private const TABLE = 'xplugin_jtl_dbbackup_tool_backuphistory';

    public function __construct(private readonly DbInterface $db)
    {
    }

    public function create(
        string $presetKey,
        string $label,
        string $filename,
        string $instanceId,
        bool $encrypted,
    ): int {
        $row = (object) [
            'dCreated'               => \date('Y-m-d H:i:s'),
            'cPresetKey'             => $presetKey,
            'cLabel'                 => $label,
            'cFilename'              => $filename,
            'cManifestFormatVersion' => ManifestService::MANIFEST_FORMAT_VERSION,
            'nSizeBytes'             => 0,
            'cStatus'                => 'running',
            'cInstanceId'            => $instanceId,
            'bEncrypted'             => $encrypted ? 1 : 0,
            'bUploaded'              => 0,
            'cLastError'             => null,
        ];

        return $this->db->insert(self::TABLE, $row);
    }

    public function markResult(int $id, string $status, int $sizeBytes, ?string $error = null): void
    {
        $row = (object) [
            'cStatus'    => $status,
            'nSizeBytes' => $sizeBytes,
            'cLastError' => $error,
        ];
        $this->db->update(self::TABLE, 'kID', $id, $row);
    }

    public function markUploaded(int $id, bool $uploaded, ?string $error = null): void
    {
        $row = (object) ['bUploaded' => $uploaded ? 1 : 0, 'cLastError' => $error];
        $this->db->update(self::TABLE, 'kID', $id, $row);
    }

    public function find(int $id): ?\stdClass
    {
        return $this->db->selectSingleRow(self::TABLE, 'kID', $id);
    }

    /**
     * @return object[]
     */
    public function all(int $limit = 200): array
    {
        return $this->db->getObjects(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY dCreated DESC LIMIT ' . \max(1, $limit),
        );
    }

    public function count(): int
    {
        return $this->db->getSingleInt('SELECT COUNT(*) AS cnt FROM ' . self::TABLE, 'cnt');
    }

    public function latest(): ?\stdClass
    {
        $rows = $this->db->getObjects('SELECT * FROM ' . self::TABLE . ' ORDER BY dCreated DESC LIMIT 1');

        return $rows[0] ?? null;
    }

    /**
     * Spec decision "Retention-Policy": rows older than the retention window
     * or beyond the max count, EXCEPT the minimum number of most recent ones
     * that must always survive regardless of age/count settings.
     *
     * @return object[] rows to delete (caller also deletes the actual files)
     */
    public function findExpired(int $maxCount, int $maxAgeDays, int $minKeep): array
    {
        $all = $this->all(100000);
        if (\count($all) <= $minKeep) {
            return [];
        }

        $protected = \array_slice($all, 0, $minKeep);
        $protectedIds = \array_map(static fn ($r) => $r->kID, $protected);
        $candidates = \array_slice($all, $minKeep);

        $cutoff = \strtotime('-' . $maxAgeDays . ' days');
        $expired = [];

        foreach ($candidates as $index => $row) {
            $tooOld = $maxAgeDays > 0 && \strtotime($row->dCreated) < $cutoff;
            $overCount = ($index + $minKeep) >= $maxCount;

            if (($tooOld || $overCount) && !\in_array($row->kID, $protectedIds, true)) {
                $expired[] = $row;
            }
        }

        return $expired;
    }

    public function delete(int $id): void
    {
        $this->db->delete(self::TABLE, 'kID', $id);
    }
}
