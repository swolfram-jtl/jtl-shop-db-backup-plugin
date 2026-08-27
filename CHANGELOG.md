# Changelog

All notable changes to this project are documented here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

- Initial plugin scaffold: `info.xml`, `Bootstrap.php`, `Service/`,
  `Cron/`, `Controller/` stubs, one `Migrations/` stub, Smarty template
  stubs for the four Adminmenu tabs.
- No backup/restore logic implemented yet — structure only.
- Verified scaffold against the actual shop core (release/5.8.0) and fixed
  several wrong first guesses: `info.xml` structure (`XMLVersion`,
  `<Setting>` attributes, `type="encrypted"`, `MinShopVersion` format),
  `Bootstrapper` lifecycle method signatures, and the `Customlink` entry
  files' real location (`adminmenu/`) and runtime context.
- Implemented the full backup/restore engine: `BackupService`,
  `RestoreService`, `ConsistencyChecker`, `AuditLogger`, plus supporting
  `StorageService`, `LockService`, `ManifestService`, `EncryptionService`,
  `RetentionService`, `NotificationService`, `MaintenanceModeService`,
  `SettingsRepository`, and FTPS/SFTP `Upload/` targets.
- Wired real logic into `Dashboard`/`Backup`/`History`/`Settings`
  controllers and their Smarty templates; expanded `info.xml` with the full
  FTPS/SFTP/encryption settings set; added a real table-creating migration
  and a real recurring `BackupCronJob`.
- Not yet tested against a real JTL-Shop instance — see README "Known gaps"
  for what's most likely to need a fix on first install.
- Fixed after the first real upload attempt failed with no clear error:
  `Bootstrap::preInstallCheck()` returned `false` (silently blocking
  installation with no attached reason) when `Ifsnop\Mysqldump\Mysqldump`
  couldn't be resolved — now always returns `true`; the same dependency
  check happens at first actual backup use instead
  (`BackupService::assertDependencyAvailable()`), where it can show a real
  message. Also fixed a real `ArgumentCountError` in `Bootstrap::preUpdate()`
  (constructed `BackupService` with 4 of 9 required arguments — harmless in
  practice since it was swallowed by a catch block, and only reachable on a
  plugin *update*, never on first install, but a genuine bug regardless).
  Factored the correct construction into a new `BackupServiceFactory` so it
  only exists in one place now. Also simplified `info.xml`'s `ftp_protocol`
  from an untested `type="selectbox"` (no confirmed `<Option>` schema) to
  plain `type="text"`, and reordered top-level `info.xml` elements to match
  the one confirmed real-world example (`Version` before `CreateDate`) —
  both are still-unconfirmed suspects, included defensively since the exact
  upload error text wasn't available to pin down the real cause.
- **Found the actual root cause of the upload failure** via the shop's own
  console output (`"info.xml existiert nicht: jtl_dbbackup_tool\Bootstrap.phpinfo.xml"`,
  `dir_name: "jtl_dbbackup_tool\Bootstrap.php"`): the distributed `.zip` was
  built with PowerShell's `Compress-Archive`, which wrote every entry path
  using backslashes (`jtl_dbbackup_tool\Bootstrap.php`) instead of the
  forward slashes the ZIP format requires as directory separators. PHP's
  zip extraction (correctly) treated each entry as one flat file with a
  literal backslash in its name rather than a folder structure, so the
  shop's installer computed a nonsensical directory name and
  info.xml path. Rebuilt the zip via `System.IO.Compression.ZipArchive`
  directly with explicit forward-slash entry names — the three "defensive"
  fixes above (`preInstallCheck`, `ftp_protocol` selectbox, element order)
  were never the actual problem, but are harmless, reasonable improvements
  kept as-is.
- Zip upload succeeded; plugin listed under "Fehlerhaft" with error code 37
  ("Diese Version von JTL-Shop ist nicht mehr kompatibel"). Target shop
  actually runs **5.8.0-rc3**, not an older version — under strict SemVer a
  pre-release build sorts BELOW its final release, so a literal
  `MinShopVersion="5.8.0"` rejects any 5.8.0 release candidate. Set to
  `5.7.0` instead: once major/minor differ (7 vs. 8) the pre-release tag no
  longer matters for the comparison, so this passes for both real 5.7.x
  shops and any 5.8.0-rc build without depending on how the core's SemVer
  library orders pre-release strings specifically (unverified). This
  plugin's PHP code itself was still only checked against actual
  `release/5.8.0` source, not real 5.7.x.
- Version check passed; next error, code 6 ("Der Plugin-Name entspricht
  nicht der Konvention"). Renamed `<Name>` from "Database Ex/Importer Backup
  Tool" to "Database Export Import Backup Tool" — the "/" is the prime
  suspect (exact allowed character set for `<Name>` unconfirmed), removed
  entirely rather than guessing at what's still allowed.
- Name fixed; next error was a **fatal, uncaught `TypeError`** from a real
  shop stack trace, confirmed via source research to be a genuine bug in
  JTL-Shop 5.8.0-rc3 itself, not this plugin: `Installer.php` reads
  `$baseNode['URL']` with no `?? ''` fallback (unlike its neighbors
  `Icon`/`ExsID`/`StoreID`, which all have one), even though `<URL>` is
  documented as optional and never checked by `PluginValidator`. A
  *missing* `<URL>` element makes `$plugin->cURL` null, which
  `Meta::loadDBMapping()` then feeds into
  `GetText::translatePluginOrCoreMessage()`'s non-nullable `string
  $original` parameter — fatal `TypeError`. Fixed by adding a non-empty
  `<URL>` element (any string works, even empty) right after `<Author>`.

- URL fix passed; next error, code 422 ("Ungültige Migration"). Root cause:
  `Migrations/Migration20260814120000.php` extended `JTL\Plugin\Migration`
  but never declared `implements IMigration` — neither
  `JTL\Update\Migration` nor `JTL\Plugin\Migration` actually implement that
  interface anywhere in their own hierarchy (only `JsonSerializable`), so
  `MigrationManager`'s `is_subclass_of($migration, IMigration::class)`
  check failed, throwing `InvalidNamespaceException` → error 422. Fixed by
  adding `use JTL\Update\IMigration;` and `implements IMigration` to the
  migration class — folder name (`Migrations/`, plural), filename/class-name
  pattern (`Migration` + 14 digits), and namespace pluralization were all
  already correct.

- **Plugin installed and ran successfully** — Settings and Backup tabs
  render correctly against a real 5.8.0-rc3 shop. First real-usage feedback
  round produced:
  - Fixed `StorageService::freeDiskSpaceBytes(): int|false` — `disk_free_space()`
    actually returns `float|false`; the wrong declaration threw a `TypeError`
    on every backup attempt.
  - Fixed a genuine duplicate-trigger bug: `PluginController::renderMenu()`
    executes every Adminmenu `<Customlink>` file on a single request (to
    pre-render all tabs, not just the visible one), so a POST to `action=""`
    was seen by both `DashboardController` and `BackupController`,
    triggering the backup twice. Added `Service/RequestGuard.php`, a
    request-scoped static flag, so the side effect only fires once
    regardless of how many controllers check for it.
  - `history.tpl`: Download/Restore are now only offered when
    `cStatus === 'ok'` and size `> 0` — a failed run has nothing usable.
  - Implemented the previously no-op "ephemeral credentials" checkbox for
    real: added host/port/protocol/username/password fields to the
    "Optionen für diesen Lauf" panel, and fixed `SettingsRepository::
    buildUploadTarget()` to have ephemeral values fully REPLACE the stored
    config for that run (it previously only filled gaps, so a stored FTP
    host always won even when ephemeral mode was checked).
  - Added `(i)` info-icon tooltips explaining what each run-option checkbox
    actually does.
  - Converted the persistent "Letzter Backup-Lauf fehlgeschlagen" banner
    (reflects real DB state, so a plain client-side dismiss would just
    reappear on reload) to a bell-icon pattern: a small warning bell in the
    Dashboard header, expandable via a native `<details>` element, instead
    of a permanent red banner. Per-action flash messages stay as normal
    dismissible Bootstrap alerts, since those are one-off request feedback,
    not lingering state.
  - Renamed the "Historie & Restore" Adminmenu tab to "Wiederherstellen" and
    grouped `info.xml` Settings into "Backup-Einstellungen" /
    "Wiederherstellen-Einstellungen" sections (`conf="N"` headings) within
    the one Settingslink form the core supports.
  - Converted `ftp_protocol` from free text to a real `type="selectbox"`
    dropdown, using the now-confirmed `<SelectboxOptions><Option
    value="..." sort="...">Label</Option></SelectboxOptions>` schema.
  - Implemented real DE+EN localization: user-facing strings now go through
    `{d__('jtl_dbbackup_tool', 'German original')}` (Smarty) /
    `\d__('jtl_dbbackup_tool', '...')` (PHP), per the confirmed gettext-based
    mechanism (`locale/<lang>/base.mo`, `X-Domain` header — NOT the
    `translatePluginOrCoreMessage()` used earlier for the URL bug, that's
    Meta-internal only). Hand-built `locale/en-GB/base.mo` (no PHP/Python/
    msgfmt available in this environment) covering the main dashboard/
    backup/history strings; German needs no catalog since the source
    strings already are German and `d__()` falls back to the original on a
    miss. Not every single string is wrapped yet (tooltip bodies, dynamic
    error messages) — see README "Known gaps".
  - Applied a shared CSS partial (`_partials/style.tpl`, built on the
    shop's own Bootstrap 4 semantic classes rather than new hardcoded
    colors) across all tabs, redesigned the Dashboard to always show its
    stat tiles/recent-activity widget (greyed out via `.dbbackup-tile--
    placeholder` when empty) with the "no backups yet" message as a
    lightbox-style overlay card instead of replacing the widgets outright,
    and moved "Komplett" to a visually prominent first position on both
    the Dashboard quick-access row and the Backup tab's preset list.

- Fatal `SmartyException: Unable to load template 'file:_partials/style.tpl'`.
  Cause: `{include file="_partials/style.tpl"}` resolves relative to Smarty's
  configured `template_dir` (the shop's own), not relative to the currently
  running template's filesystem location — since each tab is rendered via
  `$smarty->fetch($absolutePath)` outside Smarty's normal directory
  resolution, a bare relative include path can't find our own
  `adminmenu/templates/_partials/` folder. Fixed by having each Controller
  `assign('tplDir', dirname(__DIR__) . '/adminmenu/templates')` and changing
  every `{include}` to `` {include file="`$tplDir`/_partials/....tpl"} `` —
  Smarty's backtick syntax for embedding a variable inside a quoted tag
  attribute.

- Fixed "Es läuft bereits ein Vorgang" appearing stuck with no way to see or
  clear it. Root cause: `LockService` previously tried to auto-clear a
  "stale" lock by truncating the lock file's *content* — that has zero
  effect on an OS-level `flock()`, which is tied to the open file descriptor,
  not the file's bytes. The real trigger is a large "Komplett" backup on a
  real production DB (mysqldump-php is pure PHP, slower than a native
  `mysqldump` binary) outlasting PHP's default execution-time limit — a hard
  kill that skips `finally{}` and leaves the lock legitimately held by a
  request that looks "gone" from the browser. Fixed by: removing the fake
  staleness auto-clear; adding `set_time_limit(0)`/`ignore_user_abort(true)`
  to `BackupService::createBackup()` and `RestoreService::restore()` to
  prevent the underlying cause; adding `LockService::isLocked()` /
  `lockedSince()` / `forceRelease()` so the Dashboard can honestly show a
  running-operation banner with the lock's age, plus an admin-confirmed
  "Sperre manuell aufheben" button (never automatic — see the class docblock
  for why an automatic clear would risk two runs colliding).
- Fixed the Dashboard's recent-activity widget having no direct download
  buttons. Rather than link across to the Wiederherstellen tab's download
  handler (the cross-tab URL scheme was never independently verified),
  `DashboardController` now handles its own `?action=download&id=` GET
  directly — since every Adminmenu Customlink file executes on every
  request, whichever controller's check matches first serves the file
  identically, so no cross-tab link scheme needs to be known at all.
- Redesigned the Dashboard as an actual KPI widget dashboard: icon-circle
  tiles (JTL brand colors) instead of plain text blocks, a running-operation
  banner, and download buttons on recent activity.
- Added a "Wo werden Backups abgelegt?" info box to the Dashboard showing the
  absolute local storage path and the configured FTP/SFTP target (host,
  protocol, remote dir — never credentials), addressing that neither was
  visible anywhere in the UI.
- **Fixed a real, confirmed bug**: every restore-tab POST (e.g. a failed
  confirmation-word retry) bounced the admin back to the Dashboard tab on
  reload. Root cause, confirmed by reading
  `Router\Controller\Backend\PluginController::getResponse()` and
  `admin/templates/bootstrap/tpl_inc/plugin_uebersicht.tpl` directly: all
  Adminmenu tabs render into ONE page (Bootstrap tab-pane markup); which tab
  shows is decided server-side from a `kPluginAdminMenu`/`cPluginTab`
  GET/POST parameter that plain client-side tab-switching (`data-toggle=
  "tab"`) never sets. A real POST from this plugin's own forms (`action=""`)
  therefore reloads with neither parameter present, so the core defaults to
  tab 0 (Dashboard). Fixed by adding a hidden `<input type="hidden"
  name="cPluginTab" value="...">` (matching that Customlink's exact
  `info.xml` `<Name>`) to every form in `dashboard.tpl`, `backup.tpl`,
  `_partials/run-options.tpl`, and `history.tpl`.
- **Fixed a real, confirmed bug**: the `conf="N"` Settingslink section
  headings displayed the raw internal key ("SECTION_RESTORE") instead of a
  real heading. Root cause, confirmed by reading `tpl_inc/plugin_options.tpl`
  directly: for a `Config::TYPE_NOT_CONFIGURABLE` Setting, the heading shown
  is `$confItem->niceName` — which maps to the Setting's `<Name>` element,
  **not** `<Description>` and **not** `initialValue`. Had this backwards (a
  snake_case key in `<Name>`, the real heading text wasted on an
  `initialValue` that a heading-only Setting never even reads). Fixed by
  moving the actual heading text into `<Name>` for both section headings.
- **Fixed a real, confirmed bug**: `maintenance_mode_enabled`,
  `pre_restore_snapshot_enabled`, `post_restore_consistency_check_enabled`,
  and `version_fingerprint_block_enabled` never read as enabled even with
  `initialValue="Y"` and the checkbox visibly ticked. Root cause, confirmed
  by reading `tpl_inc/plugin_options.tpl` and
  `PluginController::actionConfig()` directly: JTL-Shop's own checkbox
  `<input>` has no `value=` attribute, so a checked box always POSTs the
  literal string `"on"` (the browser default), and the settings template
  only re-checks the box if the stored value `=== "on"`. An unchecked box
  submits nothing, so the stored value becomes `NULL`, not `"N"` — there
  never was a `Y`/`N` convention for checkboxes here, that was an incorrect
  assumption carried over from the text-setting convention. Fixed
  `info.xml` (`initialValue="on"` for all four, `initialValue=""` for
  `encryption_enabled`, which is meant default-off) and
  `SettingsRepository::checkbox()` (`=== 'on'` instead of `=== 'Y'`). **Note
  for already-installed instances**: `SettingsLinks::install()` only seeds
  `initialValue` into the database once, at first install — a version bump
  alone won't retroactively fix a value already stored wrong. Re-check and
  re-save the affected checkboxes once in the Settings tab after updating,
  or do a full uninstall + reinstall.
- Completed the i18n pass: every remaining user-facing PHP-side string —
  exception messages, flash messages, notification-email bodies, and
  FTPS/SFTP connection-test results across `BackupTrigger`, `BackupService`,
  `RestoreService`, `HistoryController`, `DashboardController`,
  `SettingsController`, `ConsistencyChecker`, `ManifestService`,
  `StorageService`, `LockService`, `EncryptionService`, `FtpsUploadTarget`,
  `SftpUploadTarget`, and `BackupCronJob` — now goes through `\d__()`, using
  `sprintf`-style `%s`/`%d`/`%.1f` placeholders for embedded dynamic values
  (confirmed via `Smarty/PluginCollection.php` that `\d__()` forwards extra
  args the same way core's own `__()` does). Deliberately left untranslated:
  `PresetRegistry`'s preset labels ("Kundenimport", "Gutscheine", etc.) —
  spec requires these to match the shop's own backend menu wording exactly,
  which the core already localizes on its own. Expanded
  `locale/en-GB/base.po`/`.mo` to 108 entries.

- Renamed the plugin to **"DB Backup Manager"** (`info.xml` `<Name>` and
  `<Description>` only — `PluginID` stays `jtl_dbbackup_tool`, changing that
  would orphan an already-installed instance's settings/history). Reflects
  the plugin's growth from a pure backup/restore safety net into a full
  backup manager. `<Version>` intentionally left at `0.1.0` per the note
  below — a bump needs explicit confirmation.
- Fixed a real self-deadlock bug: **restore always failed** with "Es läuft
  bereits ein Backup oder Restore", because `RestoreService::restore()`
  holds the plugin's file lock and then — with the (default-on)
  pre-restore-snapshot option — calls `BackupService::createBackup()`, which
  acquires the *same* lock file again through a separate `LockService`
  instance; `flock()` is tied to the open file descriptor, not the process,
  so the second acquire always failed instantly. `LockService` is now
  process-wide reentrant (a depth counter keyed by the lock file path) — see
  its docblock for the full mechanics.
- Investigated the "plugin isn't multi-language" report against the real
  JTL-Shop 5.8.0 core source (`includes/src/L10n/GetText.php`/`Translator.php`,
  the `gettext/gettext` package's `MoLoader.php`/`ArrayGenerator.php`, and a
  real install's `admin/locale/` directory). Confirmed the `locale/en-GB/`
  path, flat (no `LC_MESSAGES`) layout, and `base.mo`/`base.po` filenames
  were already correct (an initial guess that `en-US` might be the right
  folder name, based on the public docs' example, turned out wrong —
  `en-GB` is what a real 5.8.0 release actually ships). Rebuilt
  `locale/en-GB/base.po`/`.mo` from scratch with a hand-written, spec-verified
  binary `.mo` writer (`build-mo.ps1`, byte-for-byte round-trip-tested against
  `MoLoader.php`'s actual parsing logic) that explicitly sets
  `X-Domain: jtl_dbbackup_tool` in the catalog header — `Gettext\Translator`
  looks translations up by this domain, and it comes from the .po/.mo file's
  own metadata, not the filename. 149 entries (was 108 — the previous count
  was stale/lost along with the build script between sessions and has been
  fully reconstructed from the current source).
- Dashboard: the 4 KPI tiles are now fully brand-colored per the approved
  JTL Brand text/background combinations (Dark Blue, Light Blue, Tech Blue,
  Light Sand — not just a colored icon circle on an otherwise plain white
  card, which read poorly with generic grey text). "Schnellzugriff" is now
  its own clearly-bordered card with an explicit "ein Klick startet SOFORT
  ein Backup" hint and bolt-icon buttons — it previously sat as a bare
  heading + button row that could read as a settings/options area rather
  than an immediate-action zone.
- Built the **"Backups" tab into a full DB Backup Manager** (renamed from
  "Wiederherstellen" — same file/class names as before, only the visible
  label and scope changed):
  - Backups are grouped into a collapsible accordion by preset/type, with
    filtering (preset/status/storage) and sorting (date/size/status) via a
    GET form, and real server-side pagination (20/page) — replacing the old
    flat, un-paginated, ungrouped list.
  - New optional free-text **comment** per backup (`cComment` column, new
    migration `Migration20260827130000`) — settable when triggering a
    manual backup (`_partials/run-options.tpl`) and inline-editable at any
    time from the Manager.
  - New **delete** (single + bulk, with a per-group "select all" shortcut)
    via `BackupDeletionService` — local file + `.manifest.json` + history
    row only, deliberately never the FTP/SFTP copy (an offsite safety copy
    that a single delete click must never be able to wipe out together with
    the local one). Blocked while a backup/restore is running; single delete
    uses a plain confirm dialog, bulk delete uses a confirmation checkbox
    gate mirroring the pattern from JTL-Shop's own "Datenbank bereinigen"
    admin screen (row checkboxes, a master "select all", a checkbox gate
    before the destructive action enables). Every delete is audit-logged
    like backup/restore already were.
  - The restore preview/type-to-confirm flow moved from an inline page
    section into a modal, triggered per-row.
  - `BackupHistoryRepository::search()` is the new filtered/sorted/paginated
    query backing all of this; `RequestGuard` gained `claimDeleteAction()`/
    `claimCommentAction()` alongside its existing backup/restore guards.

- Fixed a real bug reported right after a reinstall: **the Manager showed no
  backups even though the actual files were still on the server.**
  `xplugin_jtl_dbbackup_tool_backuphistory` is dropped and recreated empty on
  every uninstall/reinstall (and would be equally empty after restoring the
  shop's own database from an older dump) — but the backup files themselves
  deliberately live outside the plugin's install footprint
  (`StorageService`'s "Speicherort außerhalb des Webroots" decision), so they
  survive untouched. New `StorageReconciliationService::reconcile()` scans
  each backup's `.manifest.json` sidecar and adds a history row for any
  committed file (`.manifest.json` + data file both present) that isn't
  already tracked — purely additive, never touches an existing row. Runs
  automatically on every Dashboard/Backups page load (cheap: one `glob()`
  diffed against a single filename query); recovered rows are visibly
  flagged via their comment ("ursprünglicher Ersteller und Upload-Status
  sind nicht mehr bekannt") since that provenance genuinely isn't
  recoverable from the manifest. Caught and fixed a real instanceId-
  truncation bug while building this: the manifest stores the *full*
  untruncated instance-id hash, while every other call site in this plugin
  truncates it to 32 chars before use — comparing them directly would have
  silently matched nothing. Also extracted the preset-label lookup (used by
  both `HistoryController` and this service) into a shared
  `PresetLabelResolver` so the two can't drift apart.

<!-- No version number assigned yet: first bump happens after explicit
     confirmation, once there's something real to release. -->
