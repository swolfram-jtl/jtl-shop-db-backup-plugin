# JTL-Shop DB Backup & Restore Plugin

A JTL-Shop 5.8 plugin that lets shop admins take targeted or full database
backups — locally and/or to an FTPS/SFTP destination — and restore them again,
so a mistake made while using one of the shop's built-in CSV import screens
(customers, newsletter recipients, ZIP/city codes, redirects, coupons,
reviews, language variables) can be undone quickly.

Deutsche Version: [README.de.md](README.de.md)

> **Status:** installed and running against a real JTL-Shop 5.8.0-rc3
> instance — Dashboard, Backup, Wiederherstellen (restore), and Settings
> tabs all render and the manual backup flow works end-to-end. Under active
> real-usage iteration; see `CHANGELOG.md` for the running log of bugs found
> and fixed this way (several — a duplicate-trigger bug, a wrong return
> type, an ephemeral-credentials UI that was previously a no-op — see
> `Service/RequestGuard.php`'s docblock for one especially non-obvious one:
> **every Adminmenu tab's PHP file runs on every single request**, not just
> the visible tab, which matters for any future controller that reads
> `$_POST`). The architecture and ~56 individual design decisions behind
> this plugin were worked out in a dedicated design review; see
> `docs/architecture-spec.html`.

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
  Controller/          Backend tab controllers (Dashboard, Backup, History & Restore, Settings)
  Migrations/           Plugin's own schema migrations (audit log, backup history tables)
  adminmenu/            Adminmenu <Customlink> entry points + their templates
                        (verified location: PFAD_PLUGIN_ADMINMENU = 'adminmenu/')
  templates/settings.tpl
                        Settings-tab extra (connection-test button) — the
                        Settingslink render/hook mechanism itself is NOT
                        verified yet, see "Known gaps"
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
plugin's own restore scope, and a Dashboard/Backup/History UI wired to real
Smarty templates.

## Known gaps — verify before relying on this in production

Nothing here was executable in the environment this was built in (no PHP
runtime, no shop instance) — this is a careful best-effort implementation
against verified core APIs where possible, but it has not run once. Before
trusting it with real data:

**Likely to need a fix on first install:**
- `Service/BackupService.php` and `RestoreService.php`'s `buildDsn()`
  assume `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` constants exist (classic
  JTL-Shop convention) to open mysqldump-php's own separate DB connection —
  not confirmed against `config.JTL-Shop.ini.php` in this shop version.
- `Cron/BackupCronJob.php` assumes `JTL\Plugin\Helper::getLoaderByPluginID()`
  is how a plugin loads its own instance from a cron context (no `$oPlugin`
  is handed to it the way Customlink files get one) — unverified. If this
  is wrong, only the *recurring scheduled* backup is affected; the manual
  "Backup jetzt" flow doesn't depend on it at all.
- The currently-logged-in admin's ID is read as
  `$_SESSION['AdminAccount']->kAdminlogin` (used for the audit log) —
  unverified property name.
- `ftp_protocol`'s `<Setting type="selectbox">` needs its option list
  defined somehow (an `<Option>`/`<Value>` child structure wasn't
  confirmed) — currently only the `initialValue="ftps"` default is set.

**Deliberate scope decisions, not bugs:**
- Manual "Backup jetzt" clicks run **synchronously** in the admin request,
  not via the cron queue, despite the spec calling for the latter
  ("Lange Backups laufen immer async") — the exact
  `CronController::addQueueEntry()` API for enqueuing a one-off parameterized
  job wasn't verified in time. Fine in practice for every preset (small
  tables); "Komplett" on a very large database could hit a PHP request
  timeout. The recurring cron job (`BackupCronJob`) is unaffected.
- The "Verbindung testen" button's connection-test *logic*
  (`SettingsController::handleConnectionTest()`) is complete, but isn't
  wired into the Settingslink-rendered form yet (see project layout above).
- Backup history/audit log lists are plain, un-paginated tables rather than
  reusing core's `pagination.tpl`/`$oBlaetterNavi` component.
- The shop-instance identifier (`ManifestService::instanceId()`) is derived
  from `Shop::getURL()`, guarded with `method_exists()` — falls back to a
  static `'unknown-instance'` string (not persisted per-install) if that
  method doesn't exist, which would weaken (not break) the multi-shop
  collision check and the cross-instance-restore block.

**Not done at all:**
- No automated tests (spec calls for a real backup/restore roundtrip test
  suite against a live test shop as a release gate — needs that test shop
  to exist first).
- No liability/disclaimer text for end users.

## Verified against the shop core (release/5.8.0)

Several rounds of research against the public core repo corrected wrong
first guesses along the way — `info.xml`'s real schema (`<XMLVersion>`,
`<Setting>` attributes, `type="encrypted"` actually working despite the docs
omitting it, `<MinShopVersion>` format), `Bootstrapper`'s exact lifecycle
signatures, where `<Customlink>` files must physically live and what's in
scope when they run, the real `JTL\DB\DbInterface` CRUD/transaction API, how
plugin migrations run automatically via `MigrationManager` (no manual
wiring needed from `Bootstrap.php`), `JTLSmarty::assign()`/`fetch()`, and
`JTL\Plugin\Data\Config::getValue()` vs. `getDecryptedValue()` for encrypted
settings. Full details are in the code's own docblocks next to each
decision, and in the conversation history that produced this.

## Confirmed against a real running install + the shop core source

Once installed on a real 5.8.0-rc3 shop, three subtle bugs surfaced that
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

## Next steps

1. Get this onto a real JTL-Shop 5.8 test instance and fix whatever the
   "Known gaps" section above predicts will break.
2. Work through the "Deliberate scope decisions" list — most are fine for a
   v1 to skip, but the async-cron-queue one is worth revisiting if
   "Komplett" backups turn out to be slow enough to time out.
3. Only then: real backup/restore roundtrip testing, per the spec's own QA
   requirement.
