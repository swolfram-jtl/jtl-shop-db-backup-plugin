# DB Backup Manager for JTL-Shop 5.8

A JTL-Shop 5.8 plugin that lets shop admins take targeted or full database
backups — locally and/or to an FTPS/SFTP destination — organize, comment on,
and delete them, and restore them again, so a mistake made while using one of
the shop's built-in CSV import screens (customers, newsletter recipients,
ZIP/city codes, redirects, coupons, reviews, language variables) can be undone
quickly. The **Backups** tab is a full manager: backups are grouped by
preset/type, filterable and sortable, individually or bulk-deletable (local
copy only — see "Known gaps" below), and can carry a free-text comment (set at
creation or edited later) documenting *why* a given backup exists.

Renamed from "Database Export Import Backup Tool" — the PluginID
(`jtl_dbbackup_tool`) is unchanged, so this is a display/docs-only rename, not
a breaking change for an already-installed instance.

Deutsche Version: [README.de.md](README.de.md)

> **Status:** **v1.0.0 — first stable release.** Installed and running
> against a real JTL-Shop 5.8.0-rc3 instance; all tabs (Dashboard, Erstellen,
> Backups verwalten (Historie) — manager + restore —, Einstellungen) render
> and the full backup/restore/cleanup flow works end-to-end. Reached this
> point through several rounds of real-usage iteration; see `CHANGELOG.md`
> for the running log of bugs found and fixed this way — including two
> genuinely severe ones caught late: a recurring cron job that silently never
> ran at all (wrong plugin-loading call under `strict_types`), and, after
> that was fixed, a second cron bug that made it re-run on *every* pseudo-cron
> trigger instead of respecting its own interval (missing `parent::start()`/
> `setFinished()` calls — see `Cron/BackupCronJob.php`'s own docblock). See
> `Service/RequestGuard.php`'s docblock for one especially non-obvious
> architectural fact that shaped a lot of this: **every Adminmenu tab's PHP
> file runs on every single request**, not just the visible tab. Still worth
> reading "Known gaps" below before relying on this in production — a few
> things are deliberately out of scope (v1) or not yet automated-tested. The
> architecture and ~56 individual design decisions behind this plugin were
> worked out in a dedicated design review; see `docs/architecture-spec.html`.

## What this is (and isn't)

- **Is**: a safety net for the database changes made by JTL-Shop's own CSV
  import screens.
- **Is not**: a CSV importer itself, a file/template backup tool, or a
  shop-cloning / staging-migration tool.

## Project layout

```
plugins/<PluginID>/
  info.xml            Plugin manifest (Adminmenu tabs, settings fields)
  Bootstrap.php        Plugin lifecycle (install/enable, cron registration)
  Service/             Backup, restore, preset, storage, lock, manifest,
                        encryption, retention, notification, settings, and
                        FTPS/SFTP upload services
  Cron/                Recurring scheduled backup job
  Controller/          Backend tab controllers (Dashboard, Backup, Backups/Manager (class
                        still named HistoryController — see its docblock), Settings —
                        SettingsPageController, a fully custom tab, not the native
                        <Settingslink> rendering — see "Confirmed against a real
                        running install" below)
  Migrations/           Plugin's own schema migrations (audit log, backup history tables)
  adminmenu/            Adminmenu <Customlink> entry points + their templates
                        (verified location: PFAD_PLUGIN_ADMINMENU = 'adminmenu/')
```

`<PluginID>` is currently the placeholder `jtl_dbbackup_tool` — rename before
a real install (and rename the `plugins/jtl_dbbackup_tool/` folder + the
`namespace Plugin\jtl_dbbackup_tool` in every PHP file to match).

## Setup

FTPS backups work out of the box (native PHP `ext-ftp`, already required by
the shop core). **SFTP** needs a real, audited SSH library rather than a
hand-rolled protocol implementation — this plugin ships its own
`composer.json` for it (plugins share the shop's Composer classmap and have
no isolated `vendor/` of their own, so this is a separately-vendored,
plugin-local dependency, not something the shop core provides):

```bash
cd plugins/jtl_dbbackup_tool
composer install --no-dev
```

Skip this if you only need local backups and/or FTPS — the plugin works
without it, and SFTP targets fail with a clear setup message instead of a
raw fatal error if `vendor/` is missing.

## What's implemented

Every core spec decision from `docs/architecture-spec.html` has a real
implementation behind it, not a stub — backup (all 7 presets + "Komplett"),
automatic restore with its full safety net (pre-restore snapshot, version/
structure fingerprint check, best-effort orphan-row consistency check,
type-to-confirm), atomic writes, a backup self-test, a concurrency lock,
disk-space pre-checks, opt-in XChaCha20-Poly1305 encryption, FTPS/SFTP
upload with an ephemeral-credentials option, a retention/rotation policy, a
recurring cron job, an audit log that's structurally excluded from the
plugin's own restore scope, and a Dashboard/Backup/Manager UI wired to real
Smarty templates. The **Backups** tab additionally provides: grouping by
preset with per-group bulk-select, filtering (preset/status/storage) and
sorting (date/size/status), real server-side pagination, free-text comments
(settable at creation, inline-editable any time), single and bulk delete
(local file + manifest + history row — gated by a confirmation dialog/
checkbox and blocked while a backup or restore is running), and the restore
preview/confirm flow in a modal. The plugin also adds itself to JTL's own
admin "Favoriten" (the star button in the header, present on every backend
page) automatically at install — see "Confirmed against a real running
install" below for why that's the supported path and a custom floating
icon isn't.

## Known gaps — verify before relying on this in production

Most of what was originally flagged here as "unverified, no PHP runtime
available at the time" has since been checked against the real shop core
source (a local copy of the release/5.8.0 codebase) or against a real
running install — see "Verified against the shop core" and "Confirmed
against a real running install" below for what that turned up, including
two previously-undiscovered bugs (the cron job type never registering, and
the recurring job silently `TypeError`-ing every single run) that are now
fixed. What's left here is genuinely still open:

**Deliberate scope decisions, not bugs:**
- Manual "Backup jetzt" clicks run **synchronously** in the admin request,
  not via the cron queue, despite the spec calling for the latter
  ("Lange Backups laufen immer async"). CHECKED (not just assumed) against
  the real `CronController::addQueueEntry(array $post): int`: it only
  supports the SAME recurring-schedule shape (`frequency`/`startDate`/
  `startTime`, one `tcron` row that runs on its own cadence going forward)
  that `Cron/BackupCronJob.php` already uses — there's no separate "enqueue
  this once, run it in the background shortly" primitive in that
  controller. A real one-off async trigger would need a different
  mechanism (a different part of the `Cron\Queue` execution model, not
  investigated further) — not simply a matter of calling this method
  differently. Fine in practice for every preset (small tables); "Komplett"
  on a very large database could hit a PHP request timeout. The recurring
  cron job is unaffected either way, and now has its own independent
  "Komplett"-only schedule option (`Cron/FullBackupCronJob`).
- The audit log's own list (not the Backups tab, which now has real
  pagination) is still a plain, un-paginated table rather than reusing a
  core pagination component — it has no admin UI of its own yet at all.
- Deleting a backup only ever removes the **local** copy (file + manifest +
  history row) — a deliberate choice, not an oversight: FTP/SFTP is meant as
  an independent offsite safety copy, so a single delete click must never be
  able to wipe out both copies at once. There is currently no UI to delete a
  remote copy at all (would need a new `delete()` method on
  `UploadTargetInterface`, not implemented).
- The shop-instance identifier (`ManifestService::instanceId()`) is derived
  from `Shop::getURL()`, guarded with `method_exists()` — CONFIRMED that
  method exists unconditionally in release/5.8.0 (`includes/src/Shop.php`),
  so the guard is low-cost insurance for the 5.7.x compatibility floor this
  plugin declares (`MinShopVersion`) but hasn't independently verified,
  rather than a real risk on 5.8.

**Not done at all:**
- No automated tests (spec calls for a real backup/restore roundtrip test
  suite against a live test shop as a release gate — needs that test shop
  to exist first).
- No liability/disclaimer text for end users.

## Verified against the shop core (release/5.8.0)

Several rounds of research against a local copy of the core source
corrected wrong first guesses along the way, or confirmed assumptions that
had been carried since early on without ever being checked:
- `info.xml`'s real schema (`<XMLVersion>`, `<Setting>` attributes,
  `type="encrypted"` actually working despite the docs omitting it,
  `<MinShopVersion>` format).
- `Bootstrapper`'s exact lifecycle signatures, where `<Customlink>` files
  must physically live and what's in scope when they run, the real
  `JTL\DB\DbInterface` CRUD/transaction API, how plugin migrations run
  automatically via `MigrationManager` (no manual wiring needed from
  `Bootstrap.php`), and `JTLSmarty::assign()`/`fetch()`.
- `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` (used by `BackupService`/
  `RestoreService`'s `buildDsn()` to open mysqldump-php's own separate DB
  connection) — CONFIRMED against `includes/src/Installation/VueInstaller.php`,
  which writes exactly these four constant names into a freshly installed
  shop's `config.JTL-Shop.ini.php`.
- `$_SESSION['AdminAccount']->kAdminlogin` (the currently-logged-in admin's
  ID, used for the audit log) — CONFIRMED against
  `includes/src/Router/Controller/Backend/AdminAccountController.php`,
  which reads/writes that exact property.
- `JTL\Plugin\Data\Config::getValue()` vs. `getDecryptedValue()` for
  encrypted settings, and the `base64(XTEA(...))` storage shape behind it —
  relevant history even though this plugin no longer uses `Config` at all
  (see the settings-storage rewrite below), since `SettingsRepository` now
  replicates that exact shape against its own table instead.

Full details are in the code's own docblocks next to each decision, and in
the conversation history that produced this.

## Confirmed against a real running install + the shop core source

Once installed on a real 5.8.0-rc3 shop, several subtle bugs surfaced that
turned out to have definitive, source-confirmed root causes and fixes (all
documented in detail in `CHANGELOG.md`):

- **Tab jumps back to Dashboard after any POST from another tab.** All
  Adminmenu tabs actually render into one page; which one is *shown* is
  decided server-side from a `kPluginAdminMenu`/`cPluginTab` request
  parameter that plain client-side tab-switching never sets. Every form now
  carries a hidden `cPluginTab` field matching its tab's exact `info.xml`
  `<Name>`.
- **`conf="N"` section headings showed a raw internal key instead of real
  text.** The heading shown for a non-configurable Setting is its `<Name>`
  element, not its `<Description>` or `initialValue` — those were swapped.
- **Checkbox settings never read as enabled despite `initialValue="Y"` and
  looking checked in the UI.** JTL-Shop's own checkbox convention is
  `"on"`/`NULL`, not `"Y"`/`"N"` — there was no real Y/N convention to begin
  with. Fixed in both `info.xml` and `SettingsRepository::checkbox()`. If
  you already installed an earlier version, re-check and re-save the
  affected checkboxes once — a plugin update doesn't retroactively rewrite
  already-stored config values.
- **Restore always failed with "a backup or restore is already running."**
  `RestoreService::restore()` acquires the plugin's file lock, then — when
  the pre-restore-snapshot option is on (its default) — calls
  `BackupService::createBackup()`, which acquires the *same* lock file again
  through its own, separately-constructed `LockService` instance. `flock()`
  is tied to the open file descriptor, not the process, so a second
  `fopen()`+`flock()` on the same path from the same PHP process doesn't see
  its own already-held lock and fails immediately. Fixed with process-wide
  reentrant locking in `LockService` (a depth counter keyed by the lock file
  path) — see its docblock for the full mechanics.
- **Plugin never appeared multi-language, even with the admin account set to
  English.** Re-verified the entire loading chain against the real
  `includes/src/L10n/GetText.php` / `Translator.php` and the
  `gettext/gettext` package's `Loader/MoLoader.php` and
  `Generator/ArrayGenerator.php`: the `locale/<lang>/base.mo` path and flat
  (no `LC_MESSAGES`) directory structure were already correct, and
  `admin/locale/` on a real 5.8.0 release confirms `en-GB` (not `en-US`, despite
  that being the example in the public docs) is the right folder name. The
  real, fixable finding: `Gettext\Translator` looks up a plugin's strings by
  domain, and the domain comes from an `X-Domain` header *inside* the .po/.mo
  file's own metadata — without it, JTL-Shop can still often paper over this
  via a shared-default-domain fallback, but it's fragile. `base.po`/`base.mo`
  are now rebuilt with `X-Domain: jtl_dbbackup_tool` set explicitly (see
  `build-mo.ps1` in the repo history / CHANGELOG), and the new `.mo` writer
  was round-trip-verified byte-for-byte against `MoLoader.php`'s actual
  parsing logic. If it's *still* not translating after this: check the
  testing admin account's language really is set to English, and clear the
  shop's locale cache (`DIR_LOCALE_CACHE`) once after deploying — JTL caches
  parsed `.mo` → PHP-array conversions keyed by file mtime.
- **Manager showed no backups after a reinstall, even though the files were
  still on the server.** The history table is dropped/recreated empty by
  any uninstall/reinstall; the backup files themselves deliberately live
  outside the plugin's own folder and survive that untouched. Added
  `StorageReconciliationService`, which runs automatically on every
  Dashboard/Backups page load and re-adds a history row for any backup file
  found on disk (via its `.manifest.json` sidecar) that isn't already
  tracked — additive only, never touches an existing row.
- **"Einstellungen" can't show always-visible help text or a conditional
  field (e.g. only reveal the encryption password once its checkbox is
  on) via the native `<Settingslink>` form.** Confirmed against
  `admin/templates/bootstrap/tpl_inc/plugin_options.tpl` and
  `help_description.tpl`: `<Setting><Description>` always renders as a
  hover-only tooltip, and `PluginController::renderMenu()` gives plugins no
  hook to inject their own JS/HTML into that auto-generated form. Replaced
  it with a fully custom Customlink tab (`SettingsPageController`). Two
  stages: first reused the native save endpoint
  (`PluginController::actionConfig()`) while keeping a demoted, sorted-last
  "Erweiterte Einstellungen (Rohformular)" fallback tab around purely to
  keep its `<Setting>` schema registered (`SettingsLinks::install()` always
  creates a visible menu entry for a `<Settingslink>`, confirmed no
  headless/schema-only option exists) — then, once that fallback tab itself
  was no longer wanted, moved settings storage into this plugin's own table
  (`Service/SettingsStore`, `Migration20260827140000`, which one-time-copies
  any already-configured native values so an upgrade doesn't lose them) and
  removed the `<Settingslink>` from `info.xml` entirely. Encrypted fields
  keep the exact same `base64(XTEA(...))` storage shape via the shop's own
  `CryptoServiceInterface`, so no new key management was introduced.
- **The recurring cron job silently did nothing on every single run** —
  independent of, and in addition to, the job-type-registration bug below:
  `Cron/BackupCronJob.php` called `Helper::getLoaderByPluginID(self::PLUGIN_ID)`
  with the plugin's STRING ID, but that method's real signature is
  `getLoaderByPluginID(int $id, ...)` — the NUMERIC `kPlugin`. Under this
  file's own `declare(strict_types=1)`, passing a string there throws a
  `TypeError` immediately, silently caught by the job's own blanket
  `catch (\Throwable)` (there to stop one plugin's failure from fataling the
  shop's whole cron run) — so even with the job type properly registered,
  every scheduled run would have thrown immediately and done nothing,
  indistinguishable from "ran, nothing configured to back up". CONFIRMED
  against `includes/src/Plugin/Helper.php`: `getPluginById(string
  $pluginID): ?PluginInterface` is the correct method for this exact case —
  takes the string ID, resolves the numeric one itself via a cached
  lookup, and returns an already-loaded plugin directly. Fixed in both
  `Cron/BackupCronJob.php` and the new `Cron/FullBackupCronJob.php`.
- **The cron job type never actually registered**, surfacing as `Undefined
  array key "jobType"` in `Bootstrap.php` from the shop's own Cron admin
  page. `Bootstrap::boot()`'s two `Dispatcher` listeners used an assumed
  event-args shape that never matched the real one. CONFIRMED against
  `CronController::getAvailableCronJobs()` and `Mapper/JobTypeToJob::map()`:
  `GET_AVAILABLE_CRONJOBS` fires `['jobs' => &$available]` (a flat list of
  type strings), and `MAP_CRONJOB_TYPE` fires `['type' => $type, 'mapping'
  => &$mapping]` — not the `jobTypes`/`jobType`/`jobClass` keys previously
  assumed. `Dispatcher::fire()` is `: void` and discards a listener's return
  value entirely, so only mutating those *referenced* array elements in
  place reaches the caller. One side effect worth knowing: the "Typ"
  dropdown in Cron → Anlegen renders via core's own `{__($type)}`, which has
  no translation for a plugin-registered type string, so it shows the raw
  identifier `plugin:jtl_dbbackup_tool_cron` verbatim rather than a nice
  label — not fixable from a plugin, documented in the Dashboard's own setup
  guide instead.
- **A backup-trigger's result message could show up on the wrong tab**
  (e.g. clicking "Backup jetzt" on "Erstellen" showing its result on
  Dashboard). Every Adminmenu Customlink file executes on every request to
  pre-render all tabs, so the same `preset` POST is visible to both
  `BackupController` and `DashboardController` — whichever one's
  `RequestGuard::claimBackupTrigger()` ran first (execution order, not the
  tab actually being looked at) kept the result local to itself. Fixed with
  `Service/FlashBus`, a request-scoped relay every controller now reads from
  when it didn't handle the action itself, rendered identically via a new
  shared `_partials/flash.tpl` at the top of every tab.
- **A live fatal Smarty compile error** ("unknown function 'function'") on
  the Backups tab, from a dense inline `onclick` containing raw JavaScript —
  Smarty's own template delimiters are also `{`/`}`, so a `{` immediately
  followed by a bareword like `function` got parsed as a malformed Smarty
  tag. Removed the inline handler; every remaining JS/CSS block across this
  plugin's templates is now wrapped in `{literal}...{/literal}` defensively,
  not just the one that actually broke.
- **A custom floating "quick access" icon, investigated on request, turned
  out to be actively unsafe rather than merely unsupported.** The only hook
  firing on nearly every backend page is `HOOK_BACKEND_FUNCTIONS_GRAVATAR`
  — but CONFIRMED against `includes/src/Smarty/BackendPlugins.php`'s
  `getAvatar()`, it fires mid-evaluation of `<img src="{getAvatar
  account=$account}">` in `header.tpl`. Anything a plugin's hook listener
  echoed there would land inside that `src="..."` attribute value and
  corrupt the page, not render as a separate element — every other
  `HOOK_BACKEND_*` constant is narrow/feature-specific, and the generic
  Smarty output-filter hooks that DO support content injection are
  explicitly disabled for the backend context (`BackendSmarty` always
  constructs with `ContextType::BACKEND`, and `JTLSmarty`'s output-filter/
  `{include}`/fetch-template hooks all guard on `ContextType::FRONTEND`).
  Used the shop's own **"Favoriten"** admin feature instead (see "What's
  implemented" above) — genuinely supported, no hook needed at all, since a
  plugin can just insert into `tadminfavs` directly via the existing
  `JTL\Backend\AdminFavorite` class.

## Next steps

1. Confirm the two cron fixes above actually work end-to-end on the real
   install: the job type now appearing in Cron → Anlegen's "Typ" dropdown,
   and a real scheduled run actually producing a backup (not just "no
   error") — the `TypeError` bug in particular could very plausibly have
   been silently failing for a while before it was caught here.
2. Work through the remaining "Deliberate scope decisions" list — most are
   fine to leave as-is, but the async-cron-queue one is worth revisiting if
   "Komplett" backups turn out to be slow enough to time out; see that
   bullet for what was actually checked and why it's not a simple swap.
3. Real backup/restore roundtrip testing, per the spec's own QA requirement
   (still the one item with no automated coverage at all — see "Not done at
   all").
