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
        ?string $comment = null,
    ): int {
        $row = (object) [
            'dCreated'               => \date('Y-m-d H:i:s'),
            'cPresetKey'             => $presetKey,
            'cLabel'                 => $label,
            'cComment'               => $comment !== '' ? $comment : null,
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

    /**
     * Spec decision "Kommentar jederzeit editierbar": inline-edit target from
     * the Manager tab, independent of `create()` — see class docblock.
     */
    public function updateComment(int $id, ?string $comment): void
    {
        $row = (object) ['cComment' => $comment !== '' ? $comment : null];
        $this->db->update(self::TABLE, 'kID', $id, $row);
    }

    /**
     * @return string[] every cFilename already tracked for this instance —
     *         used by StorageReconciliationService to diff against what's
     *         actually sitting on disk (see its docblock for why this table
     *         can drift out of sync with the storage directory).
     */
    public function allFilenamesForInstance(string $instanceId): array
    {
        $rows = $this->db->getObjects(
            'SELECT cFilename FROM ' . self::TABLE . ' WHERE cInstanceId = :instanceId',
            ['instanceId' => $instanceId],
        );

        return \array_map(static fn ($r) => $r->cFilename, $rows);
    }

    /**
     * Inserts an already-complete row for a backup file found on disk but
     * missing from this table (see StorageReconciliationService) — unlike
     * create(), this writes a finished 'ok' row directly rather than a
     * 'running' placeholder later finalized by markResult().
     */
    public function createRecovered(
        string $presetKey,
        string $label,
        string $comment,
        string $filename,
        string $instanceId,
        bool $encrypted,
        int $sizeBytes,
        string $manifestFormatVersion,
        string $dCreated,
    ): int {
        $row = (object) [
            'dCreated'               => $dCreated,
            'cPresetKey'             => $presetKey,
            'cLabel'                 => $label,
            'cComment'               => $comment,
            'cFilename'              => $filename,
            'cManifestFormatVersion' => $manifestFormatVersion,
            'nSizeBytes'             => $sizeBytes,
            'cStatus'                => 'ok',
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
     * Filtered, sorted, paginated query for the DB Backup Manager tab. Primary
     * sort is always `cPresetKey ASC` so same-preset rows stay contiguous
     * within a page (the Manager renders them as an accordion group per
     * preset) — $sortField only controls the order WITHIN each group. A
     * preset group can still be split across a page boundary; that's an
     * accepted trade-off of combining real pagination with grouping (the same
     * trade-off any admin list with both makes).
     *
     * @param array{presetKey?: ?string, status?: ?string, storage?: ?string, search?: ?string} $filters
     * @return array{rows: object[], total: int}
     */
    public function search(array $filters, string $sortField, string $sortDir, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['presetKey'])) {
            $where[] = 'cPresetKey = :presetKey';
            $params['presetKey'] = $filters['presetKey'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'cStatus = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['storage'])) {
            // 'uploaded' = also present on FTP/SFTP, 'local' = local-only —
            // every backup is always local, this only ever narrows by bUploaded.
            $where[] = 'bUploaded = :storage';
            $params['storage'] = $filters['storage'] === 'uploaded' ? 1 : 0;
        }
        if (!empty($filters['search'])) {
            $where[] = '(cComment LIKE :search OR cLabel LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where !== [] ? 'WHERE ' . \implode(' AND ', $where) : '';

        $sortColumn = match ($sortField) {
            'size'   => 'nSizeBytes',
            'status' => 'cStatus',
            default  => 'dCreated',
        };
        $sortDirSql = \strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $total = (int) $this->db->getSingleInt(
            'SELECT COUNT(*) AS cnt FROM ' . self::TABLE . ' ' . $whereSql,
            'cnt',
            $params,
        );

        $perPage = \max(1, $perPage);
        $offset = \max(0, ($page - 1) * $perPage);
        $rows = $this->db->getObjects(
            'SELECT * FROM ' . self::TABLE . ' ' . $whereSql
            . ' ORDER BY cPresetKey ASC, ' . $sortColumn . ' ' . $sortDirSql
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
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
