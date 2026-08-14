<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * The seven presets are versioned against MinShopVersion (spec: "Preset-Pflege") —
 * this list must be re-verified against the shop core before claiming compatibility
 * with a new Shop version, since there is no reliable way to detect these
 * automatically (three of the seven importers don't even share a base class).
 *
 * `consistencyHints` (spec: "Konsistenzprüfung nach Teil-Restore") are a
 * BEST-EFFORT list of column relationships based on general JTL-Shop schema
 * conventions (kXxx-style foreign-key-like columns) — the exact column
 * names have NOT been independently verified against the live schema for
 * every table here and must be checked before this is relied on to catch
 * every real orphan case; core has no DB-level foreign keys, so this can
 * only ever be an approximation, never a generic/guaranteed mechanism.
 */
final class PresetRegistry
{
    public const MIN_SHOP_VERSION = '5.8.0';

    /**
     * @return array<string, array{
     *     label: string,
     *     tables: string[],
     *     consistencyHints: array<array{column: string, table: string, referencedTable: string, referencedColumn: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'customer_import' => [
                'label'  => 'Kundenimport',
                'tables' => ['tkunde'],
                'consistencyHints' => [
                    // Other tables reference tkunde.kKunde; a customer restore can
                    // orphan these if a since-created customer disappears.
                    ['column' => 'kKunde', 'table' => 'tadresse', 'referencedTable' => 'tkunde', 'referencedColumn' => 'kKunde'],
                    ['column' => 'kKunde', 'table' => 'tbestellung', 'referencedTable' => 'tkunde', 'referencedColumn' => 'kKunde'],
                ],
            ],
            'newsletter_import' => [
                'label'  => 'Newsletter-Empfänger',
                'tables' => ['tnewsletterempfaenger', 'tnewsletterempfaengerhistory'],
                'consistencyHints' => [
                    ['column' => 'kNewsletterEmpfaenger', 'table' => 'tnewsletterempfaengerhistory', 'referencedTable' => 'tnewsletterempfaenger', 'referencedColumn' => 'kNewsletterEmpfaenger'],
                ],
            ],
            'zip_import' => [
                'label'  => 'PLZ/Ort',
                'tables' => ['tplz'],
                'consistencyHints' => [],
            ],
            'redirect_import' => [
                'label'  => 'Weiterleitungen',
                'tables' => ['tredirect'],
                'consistencyHints' => [],
            ],
            'coupon_import' => [
                'label'  => 'Gutscheine',
                'tables' => ['tkupon', 'tkuponsprache'],
                'consistencyHints' => [
                    ['column' => 'kKupon', 'table' => 'tkuponsprache', 'referencedTable' => 'tkupon', 'referencedColumn' => 'kKupon'],
                ],
            ],
            'review_import' => [
                'label'  => 'Bewertungen',
                'tables' => ['tbewertung'],
                'consistencyHints' => [
                    // tartikel/tkunde are NOT covered by any preset (products come
                    // via Wawi sync, not CSV) — a review restore referencing a
                    // since-deleted product or customer is a real, visible risk.
                    ['column' => 'kArtikel', 'table' => 'tbewertung', 'referencedTable' => 'tartikel', 'referencedColumn' => 'kArtikel'],
                    ['column' => 'kKunde', 'table' => 'tbewertung', 'referencedTable' => 'tkunde', 'referencedColumn' => 'kKunde'],
                ],
            ],
            'language_import' => [
                'label'  => 'Sprachvariablen',
                'tables' => ['tsprachwerte', 'tsprachsektion', 'tsprachiso', 'tsprachlog'],
                'consistencyHints' => [],
                // Spec decision "Preset-Kollision": exclude this plugin's own rows in
                // tsprachwerte (kPlugin filter) so a restore never reverts the plugin's
                // own UI translations. TODO: implement the exact WHERE clause once the
                // real kPlugin/section layout of tsprachwerte has been verified.
            ],
        ];
    }

    public static function get(string $presetKey): ?array
    {
        return self::all()[$presetKey] ?? null;
    }

    /**
     * "Komplett" is every table above plus the rest of the schema, EXCLUDING
     * this plugin's own tables (settings, audit log, backup history) — see spec
     * decision "Audit-Log-Integrität gegen die eigene Restore-Funktion".
     */
    public static function ownTables(): array
    {
        return [
            'xplugin_jtl_dbbackup_tool_auditlog',
            'xplugin_jtl_dbbackup_tool_backuphistory',
        ];
    }
}
