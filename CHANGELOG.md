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

- Manager UX pass after real-usage feedback ("wird schnell wirr, wenn es
  viele Backups gibt"):
  - Preset groups now start **collapsed**, except "Komplett" (spec:
    "komplette Backups sind die absolut wichtigsten") which starts expanded
    and is always sorted first — pinned at the SQL level
    (`ORDER BY (cPresetKey = 'full') DESC, ...`) so it's guaranteed to be on
    page 1 no matter how many rows other presets have, not just reordered
    within whatever the current page happened to contain.
  - Groups are now a true single-open accordion (Bootstrap `data-parent`) —
    opening one closes whichever was open before, so browsing multiple
    presets can't recreate the same clutter collapsing-by-default fixes.
- Dashboard: "Letztes Backup" now also shows which backup it was (preset/
  label), not just the date+status; the "Anzahl Backups" tile is now a link
  straight to the Backups tab; "Schnellzugriff" renamed to "Sofort-Backup".
- Settings tab: every setting's displayed label was the raw internal
  `ValueName` (e.g. "pre_restore_snapshot_enabled") instead of a real title
  — same root cause already fixed for the two section headings earlier
  (`tpl_inc/plugin_options.tpl` shows `<Setting><Name>`, not `<ValueName>`),
  just not yet applied to the individual settings themselves. All 17 now
  have a proper human title with `<ValueName>` (and therefore every
  PHP-side `SettingsRepository` lookup) left untouched; several
  `<Description>` texts were also tightened/expanded where the extra
  context is genuinely useful (e.g. what "verloren" actually costs you for
  the encryption password). New title/description strings added to the
  translation catalog (186 entries total).
- Renamed two more tabs for clarity: "Backup jetzt" → "Erstellen",
  "Backups" → "Backups verwalten (Historie)" — `cPluginTab` hidden fields
  updated across every template that submits to either tab.
- `<Version>` bumped to **0.2.0** (first real bump — explicitly confirmed).
- Manager: fixed "select all" not visually checking each group's own
  checkbox (now syncs checked/indeterminate on every change, not just
  row-level); fixed the accordion group tables' cell padding not matching
  the card-header's inset above them; restore modal restyled with brand
  colors instead of stock Bootstrap red; clarified the failure-notification
  setting sends an email.
- Dashboard: added a step-by-step "how do I set up the cron job" guide
  (collapsible, shown only while no cron job is detected), verified against
  the real `admin/templates/bootstrap/cron.tpl`.
- New per-preset quick-overview chips at the top of the Backups tab (count +
  last-created date per preset, unfiltered) — brand-colored, centered, and
  clickable (opens/scrolls to that preset's accordion group), matching the
  Dashboard's KPI-tile look after a first pass left them plain/white.
- **Replaced the native `<Settingslink>` "Einstellungen" tab with a fully
  custom one** (`Controller/SettingsPageController`, `adminmenu/settings.tpl`)
  after confirming two hard limits of the native form against the real core
  source: `<Setting><Description>` only ever renders as a hover tooltip
  (`tpl_inc/help_description.tpl`), and there's no hook for a plugin to
  inject JS/HTML into the auto-generated form (`PluginController::renderMenu()`
  fetches `tpl_inc/plugin_options.tpl` directly) — so neither "always-visible
  descriptions" nor "only show the encryption password once its checkbox is
  on" were possible there, both explicitly requested. The native Settingslink
  can't be removed outright either (`SettingsLinks::install()` always creates
  a menu entry for it, no headless option) — demoted to "Erweiterte
  Einstellungen (Rohformular)", sorted last, same fields, stock rendering,
  kept only as a fallback. The new tab reuses the native save mechanism
  entirely: it POSTs to the same page (`action=""`, like every other form
  here) with the same hidden fields the native form sends
  (`Setting=1`/`kPluginAdminMenu`/`jtl_token`), which `PluginController::
  getResponse()` processes via `actionConfig()` *before* this Customlink file
  even renders — confirmed nothing about persistence, encryption, or the
  checkbox convention needed reimplementing (including the "empty encrypted
  field keeps the existing value" behavior). `kPluginAdminMenu` for the
  demoted Settingslink is looked up at runtime (`configurable === true`),
  never hardcoded.
- **New**: cron job scope is now configurable (Einstellungen → "Cronjob-
  Einstellungen") — which presets it backs up, and whether to also include
  "Komplett" (default: off, matching the previous hardcoded behavior).
  `Cron/BackupCronJob.php` now reads this via two new
  `SettingsRepository` methods instead of always looping every preset.
- Translation catalog grown to 227 entries.
- **Fixed a live fatal error** reported from a real install: a dense inline
  `onclick` on the Manager's overview chips contained raw JavaScript
  (`setTimeout(function(){...`) whose curly braces got mis-parsed as
  malformed Smarty tags — Smarty's own delimiters are also `{`/`}`. Removed
  the inline handler (replaced with a `data-target` read by the existing
  script block) and defensively wrapped every remaining JS/CSS block across
  `history.tpl`, `settings.tpl`, and `_partials/style.tpl` in
  `{literal}...{/literal}` rather than relying on incidental spacing that
  happened to avoid the same bug elsewhere.
- **Fixed the cron job integration never actually working**, reported as
  `Undefined array key "jobType"` in `Bootstrap.php` from the shop's own Cron
  admin page. The real bug was deeper than the notice: both `Bootstrap::boot()`
  event listeners used an assumed, never-verified event contract. CONFIRMED
  against the real core source (`CronController::getAvailableCronJobs()`,
  `Mapper/JobTypeToJob::map()`): `GET_AVAILABLE_CRONJOBS` fires with
  `['jobs' => &$available]` (a flat list of type strings, not a
  `['jobTypes' => [type => label]]` map), and `MAP_CRONJOB_TYPE` fires with
  `['type' => $type, 'mapping' => &$mapping]`, not `['jobType' => ...,
  'jobClass' => ...]`. `Dispatcher::fire()` is also `: void` and never uses a
  listener's return value, so mutating the *referenced* array elements in
  place is the only way a listener's result reaches the caller — the
  previous `return $args;` never did anything. Net effect: this plugin's
  cron job type was never actually registered, silently, until PHP's
  "Undefined array key" notice made it visible. Also updated the Dashboard's
  cron setup guide: the "Typ" dropdown renders via core's own `{__($type)}`,
  which has no translation for a plugin-registered type string, so it now
  correctly tells the admin to look for the raw `plugin:jtl_dbbackup_tool_cron`
  identifier rather than a nice label that was never actually shown.
- **Fixed backup-trigger flash messages appearing on the wrong tab**
  ("Meldung erscheint im Dashboard statt im Tab"). Root cause: every
  Adminmenu Customlink file executes on every request to pre-render all
  tabs, so a `preset` POST is visible to both `BackupController` and
  `DashboardController` — whichever one's `RequestGuard::claimBackupTrigger()`
  happens to run first (an execution-order accident, not the tab the admin
  is actually looking at) "won" the action and kept the result message
  local to itself. New `Service/FlashBus` relays the result across all four
  controllers within the same request; every tab template now renders it
  via a shared `_partials/flash.tpl`, so it always shows up on whichever tab
  ends up active after the reload. The success message is also now more
  detailed per spec ("ausführliche Meldung"): filename, formatted size, and
  completion time, not just "erfolgreich erstellt".
- **Fixed backup file sizes showing "0.0 MB"** for anything under ~50 KB
  (fixed-unit `%.1f MB` formatting rounded small partial-preset backups to
  zero). New `Service/SizeFormatter` picks whichever unit (B/KB/MB/GB/TB)
  keeps the number readable, used in the Manager table and the Dashboard's
  storage-usage tile.
- **Fixed the Dashboard's "Anzahl Backups" tile doing nothing on click.**
  It was a plain `<a href="?cPluginTab=...">` — a real page reload that
  silently drops every other query-string parameter the admin URL needs
  (e.g. `pluginID`). CONFIRMED against `admin/templates/bootstrap/tpl_inc/
  plugin_uebersicht.tpl`: every tab is already a plain Bootstrap tab-pane
  living in the same page (`data-toggle="tab"`, pure client-side once
  loaded) — the tile now instead clicks the real nav-tab link by matching
  its visible text, no navigation involved.
- Dashboard: the cron setup guide is no longer hidden behind a collapse
  toggle most admins never noticed — it's shown directly whenever no cron
  job is configured (which is always, today — `$nextScheduled` reading the
  shop's real cron queue is still an open gap, see README).
- Manager: overview chips now use a CSS grid (`auto-fit`/`minmax`) instead
  of flex-wrap, so the row always fills the tab's full width instead of
  leaving empty space on a wide screen.
- **Settings tab reworked** for two reported issues: (1) "Verbindung testen"
  posted a SEPARATE form containing none of the actual field values, so
  testing an unsaved host reloaded the page and showed the field as if it
  had been cleared, then failed with "kein Host angegeben". Merged into the
  ONE settings form with two submit buttons — "Speichern" and "Speichern und
  Verbindung testen" (matching the shop's own mail-server settings pattern)
  — which always saves first, then tests using the just-submitted `$_POST`
  values directly rather than re-reading `$plugin->getConfig()` (which loads
  once per request and can still be stale immediately after a same-request
  save — see `SettingsController::handleConnectionTest()`'s docblock).
  (2) Field descriptions moved from under the input (low-contrast
  `text-muted`, easy to miss) to sit directly under the label instead, in a
  more readable dark-blue-tinted color.
- **Backup trigger flow reworked into a modal.** Every "Backup jetzt" /
  "Jetzt sichern" click (Erstellen tab AND Dashboard quick-access) now opens
  a shared modal (`_partials/backup-options-modal.tpl`) asking for a
  comment/reason and exposing the same per-run overrides (ephemeral FTP/SFTP
  credentials, encryption) that used to sit behind an easy-to-miss
  "Optionen für diesen Lauf" collapse link — spec: the modal ask is more
  discoverable. One shared modal instance per tab serves every preset
  button via `data-preset`/`data-preset-label`, populated on Bootstrap's own
  `show.bs.modal` event; the old per-preset `_partials/run-options.tpl` is
  removed. `DashboardController` now parses the same comment/encrypt/
  ephemeral `$_POST` fields `BackupController` already did, since its
  quick-access buttons post through the identical modal form.
- **New second cron job type dedicated to "Komplett".** `Cron/
  FullBackupCronJob` + a second `Bootstrap::boot()` registration
  (`plugin:jtl_dbbackup_tool_cron_full`) let an admin give the full backup
  its own independent schedule in JTL's Cron admin, instead of only being
  able to fold it into the same job as the configured-presets one via the
  existing `cron_backup_include_full` setting (still there, unchanged,
  works exactly as before for anyone who wants a single combined job).
  Dashboard's cron setup guide updated to explain both job types.
- Renamed two preset labels: `customer_import` "Kundenimport" → "Kunden"
  (the backup covers customer data generally, not just the one CSV-import
  function) and `coupon_import` "Gutscheine" → "Coupons" (the shop's own
  current term) — flows through everywhere via `PresetRegistry`, no other
  hardcoded copies existed. `info.xml`'s `<Description>` updated to match.
- Dashboard's cron setup guide reverted to hidden-by-default (a "Anleitung"
  button fades it in via a small dedicated `.dbbackup-fade-panel` class,
  opacity-based rather than Bootstrap's height-based `.collapse`) — the
  "always visible" version from the previous round turned out to be the
  wrong direction; a clear, clickable "Anleitung" trigger is more
  discoverable than the old easy-to-miss link without permanently taking up
  Dashboard space.
- Settings tab: field labels are now bold, so they visibly outrank the
  description text sitting directly under them. "Speichern und Verbindung
  testen" now uses the shop's own `btn-secondary` styling (confirmed against
  `admin/templates/bootstrap/tpl_inc/einstellungen_bearbeiten.tpl`, the
  mail-server settings page's own save-and-test button) instead of this
  plugin's usual outline-primary.
- **Removed the "Erweiterte Einstellungen (Rohformular)" fallback tab
  entirely**, by moving settings storage off the native `<Settingslink>`
  mechanism altogether. CONFIRMED against `includes/src/Plugin/Admin/
  Installation/Items/SettingsLinks.php::install()`: a `<Settingslink>` can
  never register its `<Setting>` schema without ALSO unconditionally
  creating a visible `tpluginadminmenu` menu row for it — there is no
  headless/schema-only registration path in this shop version, which is
  exactly why that fallback tab existed at all despite being unwanted.
  Settings now live in a new plugin-owned table (`Service\SettingsStore`,
  `Migration20260827140000`) instead of `tplugineinstellungen*`. Encrypted
  fields (FTP password, SFTP key/passphrase, backup encryption passphrase)
  keep the exact same storage shape — `base64(XTEA(plaintext))` via the
  shop's own `CryptoServiceInterface` — so no new key management was
  introduced and an existing install's already-configured values decrypt
  correctly with zero conversion. The migration also does a one-time,
  idempotent copy of whatever was already saved through the native form
  (`ON DUPLICATE KEY UPDATE`, safe to re-run, a no-op on a fresh install) —
  a real install already has live FTP credentials configured, and upgrading
  must not silently lose them. `Controller\SettingsPageController` now does
  its own CSRF check (`Form::validateToken()`) and its own save logic
  (deriving field name/type from the SAME `$sections` structure already
  used for rendering, not a second hardcoded field list), since it no
  longer routes through `PluginController::actionConfig()` at all.
  `SettingsRepository`'s public API (every `SettingsRepository::xyz()`
  method every other class already calls) is completely unchanged — only
  its constructor (`DbInterface` instead of `PluginInterface`, plumbed
  through 6 call sites) and internal storage changed.
- **Fixed a second, independent cron bug found while working through
  README "Known gaps": the recurring job silently did nothing on every
  single run**, even once the job-type-registration bug (previous entry)
  was fixed. `Cron/BackupCronJob.php` called `Helper::
  getLoaderByPluginID(self::PLUGIN_ID)` with the plugin's STRING ID, but
  that method's real signature is `getLoaderByPluginID(int $id, ...)` — the
  NUMERIC `kPlugin`. Under this file's `declare(strict_types=1)`, passing a
  string there throws a `TypeError` immediately, silently caught by the
  job's own blanket `catch (\Throwable)`. CONFIRMED against `includes/src/
  Plugin/Helper.php`: `getPluginById(string $pluginID): ?PluginInterface`
  is the correct method — takes the string ID directly, resolves the
  numeric one itself via a cached lookup, returns an already-loaded plugin.
  Fixed in both `Cron/BackupCronJob.php` and `Cron/FullBackupCronJob.php`.
- Closed out most of README's "Known gaps" by checking each assumption
  against the real core source: `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`
  (confirmed via `VueInstaller.php`, which writes exactly those names),
  `$_SESSION['AdminAccount']->kAdminlogin` (confirmed via
  `AdminAccountController.php`), `Shop::getURL()` always existing in
  5.8.0, and the real `CronController::addQueueEntry()` API (confirmed it
  only supports the same recurring-schedule shape `BackupCronJob` already
  uses, not a distinct one-off/async primitive — the synchronous-backup
  scope decision stands, now for a checked reason instead of an
  unverified one). The `ftp_protocol` `<Setting type="selectbox">` gap is
  moot entirely now that `<Setting>` doesn't exist in `info.xml` anymore.
- **Fixed every safety-net checkbox defaulting to OFF on a fresh install**
  ("alle Haken sind leer"), reported right after a real uninstall + full
  delete + reinstall. Root cause: the removed native `<Setting
  initialValue="on">` mechanism used to seed these values into the DB at
  INSTALL time (`SettingsLinks::install()`); the new plugin-owned
  `SettingsStore` has no equivalent seeding step, so a genuinely fresh
  install starts with an empty table and every checkbox reading as unset —
  including the four that must default to ON for their own safety net to
  actually apply (`maintenance_mode_enabled`, `pre_restore_snapshot_enabled`,
  `post_restore_consistency_check_enabled`, `version_fingerprint_block_enabled`)
  and the cron preset list (previously defaulting to "every preset").
  `SettingsRepository::checkbox()` now takes an explicit `$default`, and
  `cronBackupPresets()` has its own equivalent. Getting this right needed a
  new `SettingsStore::has()` (presence, not value) rather than a naive
  `value() === null` check: an unchecked box is stored as an explicit NULL
  once a save happens, indistinguishable from "never saved" via value()
  alone — a naive default-fallback would have silently re-enabled a
  checkbox the moment an admin explicitly turned it off and saved, which
  would have been a worse bug than the one being fixed.
  `Controller\SettingsPageController`'s own checkbox rendering now reads
  through the SAME `SettingsRepository` getters (not a separate raw-store
  read) so the checked state shown on screen can never drift from what the
  rest of the plugin actually does at runtime.
- **New: the plugin adds itself to JTL's own admin "Favoriten"** (the star
  button in the admin header, present on every backend page — CONFIRMED via
  `admin/templates/bootstrap/tpl_inc/header.tpl`/`favs_drop.tpl`), so the
  Dashboard is one click away from anywhere. `Bootstrap::installed()` adds
  it automatically for whichever admin performs the install; a new toggle
  button on the Dashboard (`Service/AdminFavoriteService`, `JTL\Backend\
  AdminFavorite`'s own `tadminfavs` table) lets any admin add/remove it
  manually too. An earlier idea — a custom floating icon injected via
  `HOOK_BACKEND_FUNCTIONS_GRAVATAR`, the only hook firing on nearly every
  backend page — was investigated and rejected as actively unsafe, not
  merely unsupported: CONFIRMED against `BackendPlugins::getAvatar()` that
  hook fires mid-evaluation of `<img src="{getAvatar ...}">`, so anything a
  plugin echoed there would land inside that `src="..."` attribute value
  and corrupt the page rather than render as a separate element. The
  favorite's deep-link (`{adminURL}/plugin/{id}?cPluginTab=Dashboard`) was
  verified against the real route registration (`Route::PLUGIN . '/{id}'`
  in `Collection.php`, read by `PluginController::getResponse()`).
- **Retention ("Max. Anzahl Backups" / "Max. Alter in Tagen") now applies
  PER backup type, not globally across every backup combined.**
  `BackupHistoryRepository::findExpired()` used to sort ALL backups
  together regardless of preset and apply the count/age/min-keep rule to
  that one combined list — a preset that runs often (e.g. daily) could
  crowd out and get a rarely-run preset's older backups deleted too, purely
  by filling the shared limit faster. Now groups by `cPresetKey` first
  (including "Komplett" and the automatic pre-update snapshot, both just
  preset keys like any other) and applies the same count/age/min-keep rule
  independently within each group, so every backup type keeps its own
  configured number regardless of how often other types run. Default for
  "Max. Anzahl Backups" raised from 10 to 15 (now per type, so the
  effective total went up correspondingly with more preset types in use).
  Both settings' labels/descriptions updated to make the per-type scope
  explicit rather than implicit.
- **Fixed a real, likely-severe cron bug reported live: scheduled backups
  ran on every pseudo-cron trigger (reported roughly every ~2 minutes),
  completely ignoring the configured interval.** Root cause CONFIRMED
  against `includes/src/Cron/Queue.php` and every core job under
  `includes/src/Cron/Job/*.php`: `BackupCronJob`/`FullBackupCronJob` never
  called `parent::start($queueEntry)` and never called
  `$this->setFinished(true)` — every core cron job does both. Skipping
  `setFinished(true)` meant `Queue::run()` never called `$job->delete()`,
  so the `tjobqueue` row this job's own trigger created was never removed —
  `Queue::loadQueueFromDB()` unconditionally reloads and re-runs every row
  still sitting in `tjobqueue` on each pseudo-cron pass, regardless of
  `tcron.nextStart`/`frequency` (`Checker::check()`, which DOES respect
  those, only ever decides whether to enqueue a brand-new row — it does
  nothing to reap one that's already there). Skipping `parent::start()`
  also meant `tcron.lastStart` was never written, which is why the admin's
  own Cron overview showed no "zuletzt gelaufen" time either — both
  symptoms were the same root cause. Fixed by calling both, exactly like
  every core job (`Job/RedirectCleanup.php`, `Job/TopSeller.php`,
  `Job/VisitorCount.php`, etc.) already does for a "does its whole job in
  one pass" cron type.
- **Cron job type label readability.** CONFIRMED against the gettext
  translator library and `admin/templates/bootstrap/cron.tpl`: the raw type
  string (`plugin:jtl_dbbackup_tool_cron`) shown in the admin Cron overview
  cannot be translated from plugin side — `{__($job->getType())}` always
  resolves against the ADMIN's own `base.mo` domain (the first one loaded,
  fixed as the gettext library's "default domain"), which a plugin has no
  supported way to register strings into without modifying core files. As a
  practical mitigation, both cron job classes now write a real, readable
  value into `tcron.name` (a column core itself renders right next to the
  raw type string, confirmed `NOT NULL` but always populated by core's own
  add-cron form with a throwaway `manuell@<timestamp>` value — safe to
  overwrite) on every run.
- **Backups created by a cron job now get a fixed comment**
  ("Automatisches Backup") **so they're identifiable in the history list**,
  passed through `BackupTrigger::trigger()`'s existing `formOptions`.
- **Backup comments are now also written into the manifest JSON sidecar
  file next to the backup**, not only this plugin's own DB table — spec:
  independently traceable even if the plugin was uninstalled and later
  reinstalled/replaced. `ManifestService::build()` now accepts and stores a
  `comment`, and a new `ManifestService::updateComment()` keeps the
  manifest in sync whenever the Manager tab's inline comment field is
  edited (best-effort/no-op if the manifest file itself is missing, e.g. a
  very old pre-manifest backup).
- **"Automatische Bereinigung" (retention/auto-cleanup) is now opt-in,
  default OFF** — same pattern as encryption. Previously, retention always
  applied using its default count/age values with no way to turn it off
  entirely; silently deleting an admin's own backups by default is a
  dangerous default for a backup tool. A new checkbox
  (`SettingsRepository::autoCleanupEnabled()`) gates
  `RetentionService::apply()` in `BackupTrigger::trigger()` — nothing is
  ever deleted automatically unless explicitly enabled. The two retention
  fields ("Max. Anzahl Backups" / "Max. Alter in Tagen") are hidden in the
  settings form until the checkbox is turned on, mirroring the existing
  encryption-passphrase reveal pattern exactly (`number()`'s field builder
  gained the same `revealedBy` support `encrypted()` already had).

<!-- Next bump also needs explicit confirmation. -->
