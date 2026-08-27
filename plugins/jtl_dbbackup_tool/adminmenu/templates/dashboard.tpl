{include file="`$tplDir`/_partials/style.tpl"}
{* Spec: "Dashboard-Inhalt" (status tiles, preset quick-access, failure banner)
   and "Leerzustand" (first-run call-to-action).
   Variables assigned by Controller\DashboardController::render():
     $hasAnyBackup (bool), $lastBackup (array|null: dCreated, cStatus, cLabel),
     $nextScheduled (string|null), $storageLocalFormatted (string), $storageFtpFormatted (string|null),
     $backupCount (int), $manageTabName (string, for the "Anzahl Backups"
       tile's link — must match the Manager Customlink's exact info.xml <Name>),
     $lastRunFailed (bool), $lastRunError (string|null),
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

{include file="`$tplDir`/_partials/flash.tpl"}

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
            <div class="card dbbackup-tile dbbackup-tile--blue shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle"><i class="fal fa-calendar-check"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow mb-1">{d__('jtl_dbbackup_tool', 'Letztes Backup')}</div>
                        <div class="dbbackup-kpi-value" style="font-size: 1.15rem;">{if $lastBackup}{$lastBackup.dCreated|escape}{else}—{/if}</div>
                        {if $lastBackup}
                        <div class="small text-truncate" style="font-weight:600;" title="{$lastBackup.cLabel|escape}">{$lastBackup.cLabel|escape}</div>
                        <span class="badge {if $lastBackup.cStatus === 'ok'}badge-success{else}badge-danger{/if}">{$lastBackup.cStatus|escape}</span>
                        {else}
                        <span class="badge badge-light text-muted">{d__('jtl_dbbackup_tool', 'keins')}</span>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 pr-md-2 mb-3">
            <div class="card dbbackup-tile dbbackup-tile--light shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle"><i class="fal fa-clock"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow mb-1">{d__('jtl_dbbackup_tool', 'Nächstes geplantes Backup')}</div>
                        <div class="dbbackup-kpi-value" style="font-size: 1.15rem;">{if $nextScheduled}{$nextScheduled|escape}{else}<span style="font-size:1rem; font-weight: 500;">{d__('jtl_dbbackup_tool', 'kein Cron-Backup aktiv')}</span>{/if}</div>
                        {if !$nextScheduled}
                        <button type="button" class="btn btn-link btn-sm p-0 small" id="dbbackup-cron-guide-toggle" onclick="event.stopPropagation();">{d__('jtl_dbbackup_tool', 'Anleitung')} →</button>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 pr-md-2 mb-3">
            <div class="card dbbackup-tile dbbackup-tile--tech shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle"><i class="fal fa-hdd"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow mb-1">{d__('jtl_dbbackup_tool', 'Speicherverbrauch')}</div>
                        <div class="dbbackup-kpi-value" style="font-size: 1.4rem;">{$storageLocalFormatted|escape}</div>
                        <div class="text-muted small">{d__('jtl_dbbackup_tool', 'lokal')}{if $storageFtpFormatted !== null} · {$storageFtpFormatted|escape} FTP{/if}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card dbbackup-tile dbbackup-tile--sand dbbackup-tile--clickable shadow-sm h-100 {if !$hasAnyBackup}dbbackup-tile--placeholder{/if}"
                 data-jump-to-tab="{$manageTabName|escape}" role="button" tabindex="0"
                 title="{d__('jtl_dbbackup_tool', 'Zu allen Backups springen')}">
                <div class="card-body d-flex align-items-center" style="gap: .9rem;">
                    <div class="dbbackup-icon-circle"><i class="fal fa-layer-group"></i></div>
                    <div class="w-100" style="min-width:0;">
                        <div class="dbbackup-eyebrow mb-1">{d__('jtl_dbbackup_tool', 'Anzahl Backups')}</div>
                        <div class="dbbackup-kpi-value">{$backupCount}</div>
                    </div>
                    <i class="fal fa-arrow-right" style="color:var(--jtl-dark-blue); opacity:.5;"></i>
                </div>
            </div>
        </div>
    </div>

    {if !$nextScheduled}
    <div class="dbbackup-fade-panel mb-3" id="dbbackup-cron-guide">
        <div class="card border-0 shadow-sm" style="background:var(--jtl-sand);">
            <div class="card-body">
                <strong>{d__('jtl_dbbackup_tool', 'Automatisches Backup per Cronjob einrichten')}</strong>
                <p class="small text-muted mb-2 mt-1">{d__('jtl_dbbackup_tool', 'Dieses Plugin plant nichts von selbst — JTL-Shop hat dafür eine eigene, zentrale Cron-Verwaltung, in der auch dieser Backup-Job eingetragen wird:')}</p>
                <ol class="small mb-0" style="padding-left:1.2rem;">
                    <li>{d__('jtl_dbbackup_tool', 'Im Backend links im Menü zu „Cron" gehen.')}</li>
                    <li>{d__('jtl_dbbackup_tool', 'Dort den Reiter zum Anlegen eines neuen Auftrags öffnen.')}</li>
                    <li>{d__('jtl_dbbackup_tool', 'Im Feld „Typ" gibt es zwei Einträge dieses Plugins — JTL-Shop zeigt hier den technischen Bezeichner, da diese Job-Typen nachträglich vom Plugin registriert werden, nicht die eigene Klartext-Bezeichnung: „plugin:jtl_dbbackup_tool_cron" sichert die unter „Einstellungen" → „Cronjob-Einstellungen" gewählten Presets, „plugin:jtl_dbbackup_tool_cron_full" sichert unabhängig davon immer „Komplett".')}</li>
                    <li>{d__('jtl_dbbackup_tool', 'Intervall in Stunden festlegen (z. B. 24 für täglich) sowie eine Startzeit außerhalb der Stoßzeiten (z. B. nachts).')}</li>
                    <li>{d__('jtl_dbbackup_tool', 'Speichern — der Auftrag erscheint danach in der Übersicht und läuft ab dann automatisch. Für getrennte Zeitpläne (z. B. Presets täglich, „Komplett" wöchentlich) beide Typen als zwei eigene Aufträge anlegen.')}</li>
                </ol>
                <p class="small text-muted mb-0 mt-2">{d__('jtl_dbbackup_tool', 'Welche Presets der erste Cronjob-Typ sichert (und ob er zusätzlich „Komplett" mit einschließt) legst du unter „Einstellungen" → „Cronjob-Einstellungen" fest — Standard ist jedes Preset einzeln, „Komplett" bewusst nicht (Performance, dafür der zweite Job-Typ).')}</p>
            </div>
        </div>
    </div>
    {/if}

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

<div class="card dbbackup-quickaccess shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex align-items-start mb-3" style="gap:.6rem;">
            <i class="fal fa-bolt text-primary mt-1"></i>
            <div>
                <h5 class="mb-1">{d__('jtl_dbbackup_tool', 'Sofort-Backup')}</h5>
                <div class="small text-muted">{d__('jtl_dbbackup_tool', 'Ein Klick öffnet einen kurzen Dialog für Kommentar/Grund und optionale Einstellungen, bevor das Backup startet.')}</div>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center" style="gap: .5rem;">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#backup-modal-dashboard"
                    data-preset="full" data-preset-label="{d__('jtl_dbbackup_tool', 'Komplett')|escape}">
                <i class="fal fa-bolt mr-1"></i> {d__('jtl_dbbackup_tool', 'Jetzt sichern: Komplett')}
            </button>
            {foreach $presets as $presetKey => $presetLabel}
            <button type="button" class="btn btn-outline-secondary" data-toggle="modal" data-target="#backup-modal-dashboard"
                    data-preset="{$presetKey|escape}" data-preset-label="{$presetLabel|escape}">
                <i class="fal fa-bolt mr-1"></i> {d__('jtl_dbbackup_tool', 'Jetzt sichern')}: {$presetLabel|escape}
            </button>
            {/foreach}
        </div>
    </div>
</div>

{include file="`$tplDir`/_partials/backup-options-modal.tpl" modalId="backup-modal-dashboard" cPluginTab="Dashboard"}

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

{* {literal}...{/literal}: see history.tpl's script block for why — Smarty's
   own delimiters are also "{"/"}", plain JS braces can be mis-parsed as
   malformed Smarty tags otherwise. No Smarty variables are needed inside
   this block, so wrapping all of it is safe.
   Fix for "Kachel im Dashboard löst bei Klick nichts aus": the "Anzahl
   Backups" tile used to be a plain <a href="?cPluginTab=..."> — a real page
   reload that silently drops any other query params the admin URL needs
   (see Controller\DashboardController's own comment on manageTabName for
   the confirmed root cause). Every tab is already a Bootstrap tab-pane
   living in the SAME page (admin/templates/bootstrap/tpl_inc/
   plugin_uebersicht.tpl — data-toggle="tab", pure client-side once loaded),
   so clicking the REAL nav-tab link is both simpler and actually reliable:
   no navigation, no query-string guessing, just Bootstrap's own tab JS. *}
<script>
{literal}
document.querySelectorAll('[data-jump-to-tab]').forEach(function (el) {
    function jump() {
        var targetName = el.dataset.jumpToTab;
        var links = document.querySelectorAll('.nav-tabs .nav-link');
        for (var i = 0; i < links.length; i++) {
            if (links[i].textContent.trim() === targetName) {
                links[i].click();
                return;
            }
        }
    }
    el.addEventListener('click', jump);
    el.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            jump();
        }
    });
});

var cronGuideToggle = document.getElementById('dbbackup-cron-guide-toggle');
var cronGuidePanel = document.getElementById('dbbackup-cron-guide');
if (cronGuideToggle && cronGuidePanel) {
    cronGuideToggle.addEventListener('click', function () {
        cronGuidePanel.classList.toggle('show');
    });
}
{/literal}
</script>

</div>
