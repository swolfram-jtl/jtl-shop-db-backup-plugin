<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Display labels for every possible `cPresetKey` value that can end up in
 * the backup history table — PresetRegistry's seven CSV-import presets plus
 * the two synthetic ones BackupTrigger/BackupService use directly ('full' for
 * "Komplett", 'self_update' for the automatic pre-update snapshot). Shared by
 * HistoryController (Manager grouping/filter labels) and
 * StorageReconciliationService (labelling rows recovered from disk) so the
 * two never drift out of sync with each other.
 */
final class PresetLabelResolver
{
    /**
     * @return array<string, string> presetKey => display label. The two
     *         synthetic entries go through \d__() since they're pure UI
     *         labels; PresetRegistry's own labels stay untranslated (spec
     *         "Preset-Benennung" — must match the shop's own backend menu
     *         wording verbatim).
     */
    public static function all(): array
    {
        $labels = ['full' => \d__('jtl_dbbackup_tool', 'Komplett')];
        foreach (PresetRegistry::all() as $key => $preset) {
            $labels[$key] = $preset['label'];
        }
        $labels['self_update'] = \d__('jtl_dbbackup_tool', 'Automatisches Backup vor Plugin-Update');

        return $labels;
    }

    public static function get(string $presetKey): string
    {
        return self::all()[$presetKey] ?? $presetKey;
    }
}
