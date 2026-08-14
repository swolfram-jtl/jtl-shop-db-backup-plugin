<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;

/**
 * Spec decision "Wartungsmodus während Backup" / "Konsistenz-Fix": toggles
 * the shop's own `wartungsmodus_aktiviert` setting (verified to be what
 * `MaintenanceModeMiddleware` reads — frontend-only, does NOT pause Cron or
 * Wawi dbeS sync; see docs/architecture-spec.html "Konsistenz-Fix"). This is
 * customer-facing courtesy only, never the actual consistency mechanism
 * (that's --single-transaction in BackupService) — so a failure here is
 * logged and swallowed, never allowed to abort a backup.
 *
 * NOT independently verified: the exact config table/column written by the
 * shop's own maintenance-mode admin toggle. Assumed to be the standard
 * `teinstellungen` (cName/cWert per kEinstellungenSektion) convention used
 * throughout core for global settings — verify against a real shop before
 * relying on this in production; failures here are non-fatal by design.
 */
final class MaintenanceModeService
{
    private const SETTING_NAME = 'wartungsmodus_aktiviert';

    public function __construct(private readonly DbInterface $db)
    {
    }

    public function enable(): bool
    {
        return $this->setValue('Y');
    }

    public function disable(): bool
    {
        return $this->setValue('N');
    }

    private function setValue(string $value): bool
    {
        try {
            $this->db->getAffectedRows(
                'UPDATE teinstellungen SET cWert = :value WHERE cName = :name',
                ['value' => $value, 'name' => self::SETTING_NAME],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
