<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;

/**
 * Spec decision "Audit-Log": the only accountability control, since the plugin
 * deliberately uses a single coarse permission for both backup and restore.
 * Spec decision "Audit-Log-Integrität": this table is permanently excluded
 * from this plugin's own restore scope (see PresetRegistry::ownTables()) — a
 * restore must never be able to erase its own trail.
 */
final class AuditLogger
{
    private const TABLE = 'xplugin_jtl_dbbackup_tool_auditlog';

    public function __construct(private readonly DbInterface $db)
    {
    }

    public function log(int $adminAccountId, string $action, string $scope, ?string $details = null): void
    {
        $row = (object) [
            'dCreated'      => \date('Y-m-d H:i:s'),
            'kAdminAccount' => $adminAccountId,
            'cAction'       => $action,
            'cScope'        => $scope,
            'cDetails'      => $details ?? '',
        ];

        $this->db->insert(self::TABLE, $row);
    }

    /**
     * @return object[]
     */
    public function recent(int $limit = 200): array
    {
        return $this->db->getObjects(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY dCreated DESC LIMIT ' . \max(1, $limit),
        );
    }
}
