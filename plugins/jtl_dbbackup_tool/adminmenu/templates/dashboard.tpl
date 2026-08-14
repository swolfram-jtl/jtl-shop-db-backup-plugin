{include file="`$tplDir`/_partials/style.tpl"}
{* Spec: "Dashboard-Inhalt" (status tiles, preset quick-access, failure banner)
   and "Leerzustand" (first-run call-to-action).
   Variables assigned by Controller\DashboardController::render():
     $hasAnyBackup (bool), $lastBackup (array|null: dCreated, cStatus, cLabel),
     $nextScheduled (string|null), $storageLocalBytes (float), $storageFtpBytes (float|null),
     $backupCount (int), $lastRunFailed (bool), $lastRunError (string|null),
     $presets (array<string,string> key => label),
     $recent (array of id/dCreated/cLabel/cStatus/nSizeBytes, max 5),
     $isLocked (bool), $lockedSince (string|null, "Y-m-d H:i:s"),
     $storageLocalPath (string, absolute filesystem path),
     $ftpSummary (array{protocol,host,remoteDir}|null),
     $flashMessage (string|null), $flashSuccess (bool)
   Forms submit with action="" — every Adminmenu tab file gets executed on
   each request to pre-render all tabs, so a relative action keeps the
   submit on whichever URL is actually loaded (see Service\RequestGuard for
   why this also means side-effecting POSTs need a request-scoped guard).
   Every form also carries a hidden cPluginTab="Dashboard" field — CONFIRMED
   fix (via Router\Controller\Backend\PluginController::getResponse() and
   admin/templates/bootstrap/tpl_inc/plugin_uebersicht.tpl) for a real bug:
   all tabs live in ONE page (Bootstrap tab-pane markup), and which one shows
   is decided server-side from a kPluginAdminMenu/cPluginTab GET/POST param
   that plain client-side tab-switching never sets. Without this hidden
   field, a POST from any non-Dashboard tab bounces back to tab 0 (Dashboard)
   on reload — cPluginTab must equal this Customlink's exact info.xml <Name>.
   Spec decision "Plugin-Sprache": user-facing strings go through
   {d__('jtl_dbbackup_tool', 'German original')} — falls back to the
   original (German) text if no translation .mo is loaded for the admin's
   current language; only locale/en-GB/base.mo exists so far (see
   locale/en-GB/base.po for the source, and README "Known gaps" for what's
   still hardcoded). *}
<div class="dbbackup-page">

{if $flashMessage}
<div class="alert alert-dismissible {if $flashSuccess}alert-success{else}alert-danger{/if} shadow-sm">
    {$flashMessage|escape}
    <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
</div>
{/if}

{if $isLocked}
<div class="alert alert-warning shadow-sm d-flex align-items-center justify-content-between flex-wrap" style="gap: .75rem; border-left: 4px solid var(--jtl-orange);">
    <div class="d-flex align-items-center" style="gap: .6rem;">
        <i class="fal fa-spinner fa-spin text-primary"></i>
        <div>
            <strong>{d__('jtl_dbbackup_tool', 'Ein Vorgang läuft bereits')}</strong>
            {if $lockedSince}
            <div class="small text-muted">{d__('jtl_dbbackup_tool', 'Gesperrt seit')} {$lockedSince|escape}</div>
            {/if}
        </div>
    </div>
    <form method="post" action="" onsubmit="return confirm('{d__('jtl_dbbackup_tool', 'Nur aufheben, wenn der laufende Vorgang wirklich nicht mehr aktiv ist (z. B. nach einem Server-Neustart). Ein noch aktiver Backup/Restore würde sonst mit einem zweiten Lauf kollidieren.')|escape:"javascript"}');">
        <input type="hidden" name="cPluginTab" value="Dashboard">
        <input type="hidden" name="force_unlock" value="1">
        <button type="submit" class="btn btn-outline-primary btn-sm">{d__('jtl_dbbackup_tool', 'Sperre manuell aufheben')}</button>
    </form>
</div>
{/if}

<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="dbbackup-eyebrow text-muted">{d__('jtl_dbbackup_tool', 'Übersicht')}</div>
    {if $lastRunFailed}
    <details>
        <summary class="dbbackup-bell-toggle text-danger">
            <i class="fal fa-bell dbbackup-bell-ring"></i>
            <span class="small font-weight-bold">{d__('jtl_dbbackup_tool', 'Letzter Lauf fehlgeschlagen')}</span>
        </summary>
        <div class="alert alert-danger shadow-sm mt-2 mb-0" style="max-width: 32rem;">
            {$lastRunError|escape}
        </div>
    </details>
    {/if}
</div>

<div class="dbbackup-widgets-wrap mb-4">
    <div class="row no-gutters" style="gap: 0;">
        <div class="col-md-3 pr-md-2 mb-3">
            <div class="card dbbackup-tile shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle dbbackup-icon-circle--blue"><i class="fal fa-calendar-check"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow text-muted mb-1">{d__('jtl_dbbackup_tool', 'Letztes Backup')}</div>
                        <div class="dbbackup-kpi-value" style="font-size: 1.15rem;">{if $lastBackup}{$lastBackup.dCreated|escape}{else}—{/if}</div>
                        {if $lastBackup}
                        <span class="badge {if $lastBackup.cStatus === 'ok'}badge-success{else}badge-danger{/if}">{$lastBackup.cStatus|escape}</span>
                        {else}
                        <span class="badge badge-light text-muted">{d__('jtl_dbbackup_tool', 'keins')}</span>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 pr-md-2 mb-3">
            <div class="card dbbackup-tile shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle dbbackup-icon-circle--light"><i class="fal fa-clock"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow text-muted mb-1">{d__('jtl_dbbackup_tool', 'Nächstes geplantes Backup')}</div>
                        <div class="dbbackup-kpi-value" style="font-size: 1.15rem;">{if $nextScheduled}{$nextScheduled|escape}{else}<span class="text-muted" style="font-size:1rem; font-weight: 500;">{d__('jtl_dbbackup_tool', 'kein Cron-Backup aktiv')}</span>{/if}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 pr-md-2 mb-3">
            <div class="card dbbackup-tile shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle dbbackup-icon-circle--tech"><i class="fal fa-hdd"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow text-muted mb-1">{d__('jtl_dbbackup_tool', 'Speicherverbrauch')}</div>
                        <div class="dbbackup-kpi-value">{$storageLocalBytes|string_format:"%.1f"}<span class="dbbackup-kpi-unit"> MB</span></div>
                        <div class="text-muted small">{d__('jtl_dbbackup_tool', 'lokal')}{if $storageFtpBytes !== null} · {$storageFtpBytes|string_format:"%.1f"} MB FTP{/if}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card dbbackup-tile shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle dbbackup-icon-circle--sand"><i class="fal fa-layer-group"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow text-muted mb-1">{d__('jtl_dbbackup_tool', 'Anzahl Backups')}</div>
                        <div class="dbbackup-kpi-value">{$backupCount}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
        <div class="card-body">
            <div class="dbbackup-eyebrow text-muted mb-2">{d__('jtl_dbbackup_tool', 'Letzte Aktivität')}</div>
            {if $recent}
                {foreach $recent as $entry}
                <div class="d-flex align-items-center justify-content-between py-1 dbbackup-recent-row">
                    <span class="text-truncate mr-2">{$entry.cLabel|escape}</span>
                    <span class="text-muted small mr-2 text-nowrap">{$entry.dCreated|escape}</span>
                    <span class="badge {if $entry.cStatus === 'ok'}badge-success{else}badge-danger{/if} mr-2">{$entry.cStatus|escape}</span>
                    {if $entry.cStatus === 'ok' && $entry.nSizeBytes > 0}
                    <a href="?action=download&id={$entry.id}" class="btn btn-sm btn-outline-primary" title="{d__('jtl_dbbackup_tool', 'Herunterladen')}">
                        <i class="fal fa-download"></i>
                    </a>
                    {else}
                    <span class="text-muted small" style="width: 2.3rem; display: inline-block; text-align:center;">—</span>
                    {/if}
                </div>
                {/foreach}
            {else}
                <div class="text-muted small">{d__('jtl_dbbackup_tool', 'Noch keine Einträge.')}</div>
            {/if}
        </div>
    </div>

    {if !$hasAnyBackup}
    <div class="dbbackup-overlay-backdrop">
        <div class="card dbbackup-overlay-card">
            <div class="card-body text-center">
                <i class="fal fa-shield-check fa-2x text-primary mb-3"></i>
                <h5>{d__('jtl_dbbackup_tool', 'Noch kein Backup vorhanden')}</h5>
                <p class="text-muted">{d__('jtl_dbbackup_tool', 'Leg dein erstes Backup an, bevor du eine der CSV-Import-Funktionen des Shops nutzt.')}</p>
                <p class="mb-0"><small class="text-muted">{d__('jtl_dbbackup_tool', 'Tipp: unter „Einstellungen” lässt sich zusätzlich ein tägliches/wöchentliches Cron-Backup aktivieren.')}</small></p>
            </div>
        </div>
    </div>
    {/if}
</div>

<h5 class="mb-3">{d__('jtl_dbbackup_tool', 'Schnellzugriff')}</h5>
<div class="d-flex flex-wrap align-items-center mb-4" style="gap: .5rem;">
    <form method="post" action="">
        <input type="hidden" name="cPluginTab" value="Dashboard">
        <input type="hidden" name="preset" value="full">
        <button type="submit" class="btn btn-primary"><i class="fal fa-database mr-1"></i> {d__('jtl_dbbackup_tool', 'Komplett')}</button>
    </form>
    {foreach $presets as $presetKey => $presetLabel}
    <form method="post" action="">
        <input type="hidden" name="cPluginTab" value="Dashboard">
        <input type="hidden" name="preset" value="{$presetKey|escape}">
        <button type="submit" class="btn btn-outline-secondary">{$presetLabel|escape}</button>
    </form>
    {/foreach}
</div>

<div class="alert alert-info shadow-sm d-flex" style="gap: .75rem; background-color: var(--jtl-sand, #EEEEE7); border-color: rgba(11,27,69,.15); color: var(--jtl-dark-blue, #0B1B45);">
    <i class="fal fa-folder-open mt-1"></i>
    <div>
        <strong>{d__('jtl_dbbackup_tool', 'Wo werden Backups abgelegt?')}</strong>
        <div class="small mt-1">
            {d__('jtl_dbbackup_tool', 'Lokaler Pfad')}:
            <code>{$storageLocalPath|escape}</code>
            <span class="text-muted">({d__('jtl_dbbackup_tool', 'außerhalb des Webroots, per .htaccess zusätzlich gegen Web-Zugriff gesperrt')})</span>
        </div>
        <div class="small mt-1">
            {if $ftpSummary}
                {d__('jtl_dbbackup_tool', 'Zusätzlich auf')} {$ftpSummary.protocol|escape}-Server:
                <code>{$ftpSummary.host|escape}{$ftpSummary.remoteDir|escape}</code>
            {else}
                <span class="text-muted">{d__('jtl_dbbackup_tool', 'Kein FTP/SFTP-Ziel konfiguriert — Backups liegen ausschließlich lokal.')}</span>
            {/if}
        </div>
    </div>
</div>

</div>
