<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\DB\DbInterface;

/**
 * Plain key-value CRUD for this plugin's own settings table — see
 * Migration20260827140000's docblock for why this replaces the native
 * `<Settingslink>`/`tplugineinstellungen*` mechanism entirely, and
 * Controller\SettingsPageController's for how encrypted fields are stored
 * here (same base64(XTEA(...)) shape the native form used, just via this
 * table instead). This class itself knows nothing about encryption, field
 * types, or the checkbox convention — SettingsRepository (read) and
 * SettingsPageController (write) own that.
 *
 * Every settings.php request builds the full ~20-field settings form
 * (SettingsPageController::buildSections()) even on tabs OTHER than
 * "Einstellungen" (every Adminmenu Customlink file executes on every
 * request — see Service\RequestGuard's docblock), so get() is called
 * roughly once per field per page load. Reads are cached after the first
 * query per instance to keep that at one SELECT instead of ~20.
 */
final class SettingsStore
{
    private const TABLE = 'xplugin_jtl_dbbackup_tool_settings';

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly DbInterface $db)
    {
    }

    public function get(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Distinguishes "never saved at all" from "saved, currently empty/NULL"
     * — get() alone can't: an unchecked checkbox is stored as an explicit
     * NULL (see SettingsPageController::persist()), which reads back
     * indistinguishable from "no row exists yet" via get() alone. Needed
     * so SettingsRepository's checkbox defaults (several must default to
     * ON on a fresh install) only apply when a setting was truly never
     * saved — never re-applying a default over an admin's own explicit
     * "off" after they've saved the form once.
     */
    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->all());
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->getObjects('SELECT cName, cValue FROM ' . self::TABLE) as $row) {
                $this->cache[$row->cName] = $row->cValue;
            }
        }

        return $this->cache;
    }

    public function set(string $name, ?string $value): void
    {
        $row = (object) ['cName' => $name, 'cValue' => $value];
        $exists = $this->db->select(self::TABLE, 'cName', $name);
        if ($exists !== null) {
            $this->db->update(self::TABLE, 'cName', $name, $row);
        } else {
            $this->db->insert(self::TABLE, $row);
        }

        if ($this->cache !== null) {
            $this->cache[$name] = $value;
        }
    }
}
