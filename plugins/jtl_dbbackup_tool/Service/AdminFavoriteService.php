<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

use JTL\Backend\AdminFavorite;
use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Shop;

/**
 * Thin wrapper around the shop's own `JTL\Backend\AdminFavorite` — backs the
 * "Favoriten" star button in the admin header, CONFIRMED present on every
 * authenticated backend page (`admin/templates/bootstrap/tpl_inc/header.tpl`
 * includes `favs_drop.tpl` unconditionally). Spec: a DB backup tool should
 * be reachable in one click from anywhere in the admin, since it's
 * safety-critical. This is the supported, zero-risk way to achieve that —
 * an earlier idea (a custom floating icon injected via
 * `HOOK_BACKEND_FUNCTIONS_GRAVATAR`, the only hook firing on nearly every
 * backend page) turned out to be unsafe: that hook fires mid-evaluation of
 * `<img src="{getAvatar ...}">` (CONFIRMED against `BackendPlugins::
 * getAvatar()`), so anything a plugin echoed there would land inside that
 * `src="..."` attribute and corrupt the page, not render as a clean
 * element. `AdminFavorite` needs no hook at all — a plugin can just insert
 * directly into `tadminfavs`, the exact table the native star button reads.
 *
 * Matching an existing favorite is done via a `plugin/{id}` substring check
 * on the stored (not reconstructed) `cUrl`, not an exact string/URL
 * comparison — `AdminFavorite::add()` round-trips a URL through
 * `JTL\Helpers\URL::normalize()` (percent-encoding/path normalization)
 * before storing it, so relying on the stored string matching our
 * freshly-built one byte-for-byte would be fragile.
 */
final class AdminFavoriteService
{
    private const TITLE = 'DB Backup Manager';

    public function __construct(private readonly DbInterface $db)
    {
    }

    /**
     * The plugin's Dashboard tab, deep-linkable from a fresh page load (not
     * the same-page Bootstrap-tab click this plugin's own templates use
     * elsewhere) — CONFIRMED against `Router\Controller\Backend\
     * PluginController::getResponse()` (route `plugin/{id}`, reads
     * `kPlugin` from the URL PATH, not a query param) and `Collection.php`'s
     * route registration (`Route::PLUGIN . '/{id}'`).
     */
    public function url(PluginInterface $plugin): string
    {
        return Shop::getAdminURL() . '/plugin/' . $plugin->getID() . '?cPluginTab=' . \rawurlencode('Dashboard');
    }

    public function isFavorited(int $adminAccountId, PluginInterface $plugin): bool
    {
        if ($adminAccountId <= 0) {
            return false;
        }

        return $this->find($adminAccountId, $plugin) !== null;
    }

    public function add(int $adminAccountId, PluginInterface $plugin): bool
    {
        if ($adminAccountId <= 0 || $this->isFavorited($adminAccountId, $plugin)) {
            return false;
        }

        return (new AdminFavorite($this->db))->add($adminAccountId, self::TITLE, $this->url($plugin));
    }

    public function remove(int $adminAccountId, PluginInterface $plugin): void
    {
        if ($adminAccountId <= 0) {
            return;
        }

        $favorite = new AdminFavorite($this->db);
        $existing = $this->find($adminAccountId, $plugin);
        if ($existing !== null) {
            $favorite->remove($adminAccountId, (int) $existing->kAdminfav);
        }
    }

    private function find(int $adminAccountId, PluginInterface $plugin): ?\stdClass
    {
        $needle = 'plugin/' . $plugin->getID();
        foreach ((new AdminFavorite($this->db))->fetchAll($adminAccountId) as $favorite) {
            if (\str_contains($favorite->cUrl, $needle)) {
                return $favorite;
            }
        }

        return null;
    }
}
