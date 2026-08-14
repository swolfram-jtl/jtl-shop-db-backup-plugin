<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;

/**
 * Spec decision "Konsistenzprüfung nach Teil-Restore": core has no DB-level
 * foreign keys (verified against Migrations/ in the shop core — consistency
 * is application-level only), so a partial restore never errors even when it
 * leaves orphaned references. Best-effort only: checks known reference
 * columns per preset (PresetRegistry::consistencyHints) against the live
 * table state, not a generic FK mechanism.
 */
final class ConsistencyChecker
{
    public function __construct(private readonly DbInterface $db)
    {
    }

    /**
     * @return string[] human-readable warnings, empty if nothing suspicious found
     */
    public function checkAfterPartialRestore(string $presetKey): array
    {
        $preset = PresetRegistry::get($presetKey);
        if ($preset === null || $preset['consistencyHints'] === []) {
            return [];
        }

        $warnings = [];
        foreach ($preset['consistencyHints'] as $hint) {
            $orphanCount = $this->countOrphans(
                $hint['table'],
                $hint['column'],
                $hint['referencedTable'],
                $hint['referencedColumn'],
            );

            if ($orphanCount > 0) {
                $warnings[] = \d__(
                    'jtl_dbbackup_tool',
                    '%d Zeile(n) in `%s` verweisen über `%s` auf nicht (mehr) vorhandene Einträge in `%s`.',
                    $orphanCount,
                    $hint['table'],
                    $hint['column'],
                    $hint['referencedTable'],
                );
            }
        }

        return $warnings;
    }

    private function countOrphans(string $table, string $column, string $refTable, string $refColumn): int
    {
        try {
            return $this->db->getSingleInt(
                "SELECT COUNT(*) AS cnt FROM `{$table}` t "
                . "WHERE t.`{$column}` IS NOT NULL AND t.`{$column}` != 0 "
                . "AND NOT EXISTS (SELECT 1 FROM `{$refTable}` r WHERE r.`{$refColumn}` = t.`{$column}`)",
                'cnt',
            );
        } catch (\Throwable) {
            // Best-effort by design (spec: no generic FK mechanism exists) — a
            // missing/renamed column must not break the whole restore flow,
            // just skip that one hint silently.
            return 0;
        }
    }
}
