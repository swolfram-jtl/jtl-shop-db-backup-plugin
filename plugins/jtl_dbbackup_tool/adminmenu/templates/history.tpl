{include file="`$tplDir`/_partials/style.tpl"}
{* "Backups" tab — the DB Backup Manager. Variables assigned by
   Controller\HistoryController::render():
     $groups (array of {presetKey, presetLabel, rows: [{id, dCreated, cLabel,
       cComment, cStatus, nSizeBytes(MB), bEncrypted, bUploaded}, ...]}),
     $overviewTiles (array of {presetKey, presetLabel, count, lastCreated} —
       quick-overview chips, unfiltered/whole-table, "Komplett" always first),
     $presetOptions (array<string,string> key=>label, for the filter dropdown),
     $filterPreset/$filterStatus/$filterStorage/$search (string, current GET values),
     $sortField/$sortDir (string), $sortLinks (array<string,string> field=>href),
     $page/$totalPages/$totalCount (int), $pageNumbers (int[], 1..totalPages),
     $filterQuery (string, for pagination hrefs),
     $isLocked (bool),
     $flashMessage (string|null), $flashSuccess (bool),
     $previewBackupId (string|null), $previewDiff, $previewWarnings,
     $previewVersionMismatch (bool), $previewDecryptionPassphrase (string|null)
   Filters/sort/page use a GET form (bookmarkable); every mutating action
   (preview/restore/comment/delete/bulk_delete) stays POST action="" like the
   rest of this plugin — see Controller\HistoryController's header comment for
   why both compose cleanly. Every form carries cPluginTab="Backups". *}
<div class="dbbackup-page">

{if $flashMessage}
<div class="alert alert-dismissible {if $flashSuccess}alert-success{else}alert-danger{/if} shadow-sm">
    {$flashMessage|escape}
    <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
</div>
{/if}

{if $isLocked}
<div class="alert alert-warning shadow-sm d-flex align-items-center" style="gap:.6rem; border-left: 4px solid var(--jtl-orange);">
    <i class="fal fa-spinner fa-spin"></i>
    <div>{d__('jtl_dbbackup_tool', 'Ein Backup oder Restore läuft gerade — Löschen ist bis dahin gesperrt.')}</div>
</div>
{/if}

{if $overviewTiles}
<div class="d-flex flex-wrap mb-4" style="gap:.6rem;">
    {foreach $overviewTiles as $tile}
    <div class="dbbackup-summary-chip {if $tile.presetKey === 'full'}dbbackup-summary-chip--full{/if}">
        <div class="dbbackup-summary-chip-label">{$tile.presetLabel|escape}</div>
        <div class="d-flex align-items-baseline" style="gap:.4rem;">
            <span class="dbbackup-summary-chip-count">{$tile.count}</span>
            <span class="small text-muted">{d__('jtl_dbbackup_tool', 'zuletzt')}: {$tile.lastCreated|escape}</span>
        </div>
    </div>
    {/foreach}
</div>
{/if}

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" action="" class="form-row align-items-end">
            <input type="hidden" name="cPluginTab" value="Backups verwalten (Historie)">
            <div class="col-md-2 mb-2">
                <label class="small mb-1">{d__('jtl_dbbackup_tool', 'Preset')}</label>
                <select name="f_preset" class="form-control form-control-sm">
                    <option value="">{d__('jtl_dbbackup_tool', 'Alle')}</option>
                    {foreach $presetOptions as $key => $label}
                    <option value="{$key|escape}" {if $filterPreset === $key}selected{/if}>{$label|escape}</option>
                    {/foreach}
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label class="small mb-1">{d__('jtl_dbbackup_tool', 'Status')}</label>
                <select name="f_status" class="form-control form-control-sm">
                    <option value="">{d__('jtl_dbbackup_tool', 'Alle')}</option>
                    <option value="ok" {if $filterStatus === 'ok'}selected{/if}>{d__('jtl_dbbackup_tool', 'ok')}</option>
                    <option value="failed" {if $filterStatus === 'failed'}selected{/if}>{d__('jtl_dbbackup_tool', 'fehlgeschlagen')}</option>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label class="small mb-1">{d__('jtl_dbbackup_tool', 'Speicherort')}</label>
                <select name="f_storage" class="form-control form-control-sm">
                    <option value="">{d__('jtl_dbbackup_tool', 'Alle')}</option>
                    <option value="local" {if $filterStorage === 'local'}selected{/if}>{d__('jtl_dbbackup_tool', 'nur lokal')}</option>
                    <option value="uploaded" {if $filterStorage === 'uploaded'}selected{/if}>{d__('jtl_dbbackup_tool', 'zusätzlich hochgeladen')}</option>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <label class="small mb-1">{d__('jtl_dbbackup_tool', 'Suche in Kommentar/Bezeichnung')}</label>
                <input type="text" name="q" value="{$search|escape}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 mb-2 d-flex" style="gap:.4rem;">
                <button type="submit" class="btn btn-primary btn-sm">{d__('jtl_dbbackup_tool', 'Filtern')}</button>
                <a href="?cPluginTab={'Backups verwalten (Historie)'|escape:'url'}" class="btn btn-outline-secondary btn-sm">{d__('jtl_dbbackup_tool', 'Zurücksetzen')}</a>
            </div>
        </form>
    </div>
</div>

{* Bulk delete's confirmation is the checkbox gate below (mirrors JTL-Shop's
   own "Datenbank bereinigen" admin screen pattern) — deliberately no extra
   JS confirm() popup on top of it, unlike the single-row delete below. *}
<form method="post" action="" id="bulk-delete-form">
    <input type="hidden" name="cPluginTab" value="Backups verwalten (Historie)">
    <input type="hidden" name="action" value="bulk_delete">
</form>

{if $totalCount > 0}
<div class="card shadow-sm mb-3">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap" style="gap:.75rem;">
        <div class="d-flex align-items-center" style="gap:.6rem;">
            <input type="checkbox" id="dbbackup-select-all">
            <label for="dbbackup-select-all" class="mb-0 small">{d__('jtl_dbbackup_tool', 'Alle auswählen (auf dieser Seite)')}</label>
            <span class="text-muted small">— <span id="dbbackup-selected-count">0</span> {d__('jtl_dbbackup_tool', 'ausgewählt')}</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:.75rem;">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="dbbackup-bulk-confirm" form="bulk-delete-form">
                <label class="form-check-label small" for="dbbackup-bulk-confirm">{d__('jtl_dbbackup_tool', 'Ja, ich möchte die ausgewählten Backups unwiderruflich löschen.')}</label>
            </div>
            <button type="submit" form="bulk-delete-form" id="dbbackup-bulk-delete-btn" class="btn btn-primary btn-sm" disabled data-locked="{if $isLocked}1{else}0{/if}">
                <i class="fal fa-trash-alt mr-1"></i>{d__('jtl_dbbackup_tool', 'Ausgewählte löschen')}
            </button>
        </div>
    </div>
</div>
{/if}

{* True single-open accordion (data-parent) so opening one preset group
   auto-closes whichever was open before — with potentially many presets,
   letting several stay open at once would recreate the exact clutter
   collapsing-by-default is meant to avoid. Only "Komplett" starts expanded
   (spec: "Komplett ist am wichtigsten") — every other group starts
   collapsed. "Komplett" is also always the first group, guaranteed by
   BackupHistoryRepository::search()'s ORDER BY, not just this template. *}
<div id="backupGroupsAccordion">
{foreach $groups as $group}
<div class="card shadow-sm mb-3 dbbackup-preset-card">
    <div class="card-header d-flex align-items-center justify-content-between" style="gap:.6rem; cursor:pointer;" data-toggle="collapse" data-target="#group-{$group.presetKey}" role="button">
        <div class="d-flex align-items-center" style="gap:.6rem;" onclick="event.stopPropagation();">
            <input type="checkbox" class="dbbackup-group-select" data-group="{$group.presetKey|escape}" onclick="event.stopPropagation();">
            <strong>{$group.presetLabel|escape}</strong>
            <span class="badge badge-light text-muted">{$group.rows|count}</span>
        </div>
        <i class="fal fa-chevron-down text-muted"></i>
    </div>
    <div class="collapse{if $group.presetKey === 'full'} show{/if}" id="group-{$group.presetKey}" data-parent="#backupGroupsAccordion">
        <table class="table table-striped table-sm mb-0">
            <thead>
                <tr>
                    <th style="width:2rem;"></th>
                    <th><a href="{$sortLinks.date}" class="text-body">{d__('jtl_dbbackup_tool', 'Erstellt')}{if $sortField === 'date'} <i class="fal fa-arrow-{if $sortDir === 'ASC'}up{else}down{/if}"></i>{/if}</a></th>
                    <th>{d__('jtl_dbbackup_tool', 'Bezeichnung / Kommentar')}</th>
                    <th><a href="{$sortLinks.status}" class="text-body">{d__('jtl_dbbackup_tool', 'Status')}{if $sortField === 'status'} <i class="fal fa-arrow-{if $sortDir === 'ASC'}up{else}down{/if}"></i>{/if}</a></th>
                    <th><a href="{$sortLinks.size}" class="text-body">{d__('jtl_dbbackup_tool', 'Größe')}{if $sortField === 'size'} <i class="fal fa-arrow-{if $sortDir === 'ASC'}up{else}down{/if}"></i>{/if}</a></th>
                    <th>{d__('jtl_dbbackup_tool', 'Speicherort')}</th>
                    <th class="text-right">{d__('jtl_dbbackup_tool', 'Aktionen')}</th>
                </tr>
            </thead>
            <tbody>
            {foreach $group.rows as $row}
                <tr>
                    <td><input type="checkbox" class="dbbackup-row-check" name="ids[]" value="{$row.id}" form="bulk-delete-form" data-group="{$group.presetKey|escape}"></td>
                    <td class="text-nowrap">{$row.dCreated|escape}</td>
                    <td>
                        <div>{$row.cLabel|escape}{if $row.bEncrypted} <i class="fal fa-lock text-muted small" title="{d__('jtl_dbbackup_tool', 'Verschlüsselt')}"></i>{/if}</div>
                        <span id="comment-view-{$row.id}" class="small text-muted">
                            {if $row.cComment}{$row.cComment|escape}{else}<em>{d__('jtl_dbbackup_tool', 'kein Kommentar')}</em>{/if}
                            <a href="#" class="ml-1" onclick="document.getElementById('comment-view-{$row.id}').style.display='none';document.getElementById('comment-edit-{$row.id}').style.display='inline-flex';return false;" title="{d__('jtl_dbbackup_tool', 'Kommentar bearbeiten')}"><i class="fal fa-pencil"></i></a>
                        </span>
                        <form method="post" action="" id="comment-edit-{$row.id}" class="d-none align-items-center" style="gap:.3rem;">
                            <input type="hidden" name="cPluginTab" value="Backups verwalten (Historie)">
                            <input type="hidden" name="action" value="comment">
                            <input type="hidden" name="id" value="{$row.id}">
                            <input type="text" name="comment" value="{$row.cComment|escape}" maxlength="255" class="form-control form-control-sm" style="width:12rem;">
                            <button type="submit" class="btn btn-sm btn-outline-primary">{d__('jtl_dbbackup_tool', 'Speichern')}</button>
                        </form>
                    </td>
                    <td><span class="badge {if $row.cStatus === 'ok'}badge-success{elseif $row.cStatus === 'failed'}badge-danger{else}badge-secondary{/if}">{$row.cStatus|escape}</span></td>
                    <td class="text-nowrap">{$row.nSizeBytes|string_format:"%.1f"} MB</td>
                    <td>
                        {if $row.bUploaded}<span class="badge" style="background:var(--jtl-light-blue);color:var(--jtl-dark-blue);">{d__('jtl_dbbackup_tool', 'hochgeladen')}</span>
                        {else}<span class="badge badge-light text-muted">{d__('jtl_dbbackup_tool', 'nur lokal')}</span>{/if}
                    </td>
                    <td class="text-right text-nowrap">
                        {if $row.cStatus === 'ok' && $row.nSizeBytes > 0}
                        <a href="?action=download&amp;id={$row.id|escape}" class="btn btn-sm btn-outline-secondary" title="{d__('jtl_dbbackup_tool', 'Download')}"><i class="fal fa-download"></i></a>
                        <form method="post" action="" class="d-inline">
                            <input type="hidden" name="cPluginTab" value="Backups verwalten (Historie)">
                            <input type="hidden" name="action" value="preview">
                            <input type="hidden" name="id" value="{$row.id|escape}">
                            {if $row.bEncrypted}
                            <input type="password" name="decryption_passphrase" placeholder="{d__('jtl_dbbackup_tool', 'Passwort')}" class="form-control form-control-sm d-inline-block" style="width:7rem;">
                            {/if}
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="{d__('jtl_dbbackup_tool', 'Wiederherstellen')}"><i class="fal fa-history"></i></button>
                        </form>
                        {/if}
                        <form method="post" action="" class="d-inline" onsubmit="return confirm('{d__('jtl_dbbackup_tool', 'Dieses Backup wirklich unwiderruflich löschen? (Nur lokal — eine eventuelle FTP/SFTP-Kopie bleibt bestehen.)')|escape:"javascript"}');">
                            <input type="hidden" name="cPluginTab" value="Backups verwalten (Historie)">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{$row.id|escape}">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="{d__('jtl_dbbackup_tool', 'Löschen')}" {if $isLocked}disabled{/if}><i class="fal fa-trash-alt"></i></button>
                        </form>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
{foreachelse}
<div class="card shadow-sm">
    <div class="card-body text-muted text-center py-5">
        {if $filterPreset || $filterStatus || $filterStorage || $search}
            {d__('jtl_dbbackup_tool', 'Keine Backups gefunden, die zu den aktuellen Filtern passen.')}
        {else}
            {d__('jtl_dbbackup_tool', 'Noch keine Backups vorhanden.')}
        {/if}
    </div>
</div>
{/foreach}
</div>

{if $totalPages > 1}
<nav class="d-flex justify-content-between align-items-center mt-3">
    <div class="small text-muted">{d__('jtl_dbbackup_tool', 'Seite')} {$page} {d__('jtl_dbbackup_tool', 'von')} {$totalPages} ({$totalCount} {d__('jtl_dbbackup_tool', 'Backups gesamt')})</div>
    <div class="btn-group">
        {if $page > 1}<a class="btn btn-sm btn-outline-secondary" href="?{$filterQuery}&amp;page={$page-1}">&laquo;</a>{/if}
        {foreach $pageNumbers as $p}
            <a class="btn btn-sm {if $p === $page}btn-primary{else}btn-outline-secondary{/if}" href="?{$filterQuery}&amp;page={$p}">{$p}</a>
        {/foreach}
        {if $page < $totalPages}<a class="btn btn-sm btn-outline-secondary" href="?{$filterQuery}&amp;page={$page+1}">&raquo;</a>{/if}
    </div>
</nav>
{/if}

{if $previewBackupId}
<div class="modal fade dbbackup-restore-modal" id="restoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header dbbackup-restore-modal-header">
                <h5 class="modal-title"><i class="fal fa-exclamation-triangle mr-2"></i>{d__('jtl_dbbackup_tool', 'Wiederherstellung vorbereiten — dieser Vorgang überschreibt aktuelle Daten')}</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                {if $previewVersionMismatch}
                <div class="alert alert-warning">
                    <strong>{d__('jtl_dbbackup_tool', 'Tabellenstruktur weicht vom Backup-Zeitpunkt ab')}</strong> ({d__('jtl_dbbackup_tool', 'Shop-Update oder andere Migration seitdem')}).
                    {d__('jtl_dbbackup_tool', 'Eine Wiederherstellung könnte inkonsistente Daten erzeugen.')}
                </div>
                {/if}

                {if $previewWarnings}
                <div class="alert alert-warning">
                    <strong>{d__('jtl_dbbackup_tool', 'Mögliche verwaiste Datensätze nach dieser Wiederherstellung:')}</strong>
                    <ul class="mb-0">
                        {foreach $previewWarnings as $warning}<li>{$warning|escape}</li>{/foreach}
                    </ul>
                </div>
                {/if}

                <table class="table table-sm">
                    <thead><tr><th>{d__('jtl_dbbackup_tool', 'Tabelle')}</th><th>{d__('jtl_dbbackup_tool', 'Jetzt')}</th><th>{d__('jtl_dbbackup_tool', 'Im Backup')}</th></tr></thead>
                    <tbody>
                    {foreach $previewDiff as $table => $counts}
                        {if $counts.before !== $counts.after}
                        <tr><td>{$table|escape}</td><td>{$counts.before}</td><td>{$counts.after}</td></tr>
                        {/if}
                    {/foreach}
                    </tbody>
                </table>
                <a class="small" data-toggle="collapse" href="#unchanged-tables">{d__('jtl_dbbackup_tool', 'unveränderte Tabellen anzeigen')}</a>
                <div class="collapse" id="unchanged-tables">
                    <table class="table table-sm mt-2">
                    {foreach $previewDiff as $table => $counts}
                        {if $counts.before === $counts.after}
                        <tr><td>{$table|escape}</td><td>{$counts.before}</td><td>{$counts.after}</td></tr>
                        {/if}
                    {/foreach}
                    </table>
                </div>

                <form method="post" action="" class="mt-3">
                    <input type="hidden" name="cPluginTab" value="Backups verwalten (Historie)">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="id" value="{$previewBackupId|escape}">
                    {if $previewDecryptionPassphrase}
                    <input type="hidden" name="decryption_passphrase" value="{$previewDecryptionPassphrase|escape}">
                    {/if}
                    {if $previewVersionMismatch}
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="force_override" id="force-override">
                        <label class="form-check-label" for="force-override">{d__('jtl_dbbackup_tool', 'Trotz abweichender Tabellenstruktur fortfahren (ich weiß, was ich tue)')}</label>
                    </div>
                    {/if}
                    <div class="form-group">
                        <label>{d__('jtl_dbbackup_tool', 'Zum Bestätigen')} <code>RESTORE</code> {d__('jtl_dbbackup_tool', 'eintippen')}:</label>
                        <input type="text" name="confirmation" class="form-control" autocomplete="off" required>
                    </div>
                    <button type="submit" class="btn btn-danger">{d__('jtl_dbbackup_tool', 'Jetzt wiederherstellen')}</button>
                </form>
            </div>
        </div>
    </div>
</div>
{/if}

<script>
(function () {
    function updateBulkBar() {
        var checked = document.querySelectorAll('.dbbackup-row-check:checked').length;
        var countEl = document.getElementById('dbbackup-selected-count');
        if (countEl) { countEl.textContent = checked; }
        var confirmBox = document.getElementById('dbbackup-bulk-confirm');
        var btn = document.getElementById('dbbackup-bulk-delete-btn');
        if (btn) {
            var locked = btn.dataset.locked === '1';
            btn.disabled = locked || !(checked > 0 && confirmBox && confirmBox.checked);
        }

        // Keep every group checkbox (and the master "select all") in sync
        // with what's actually selected within it — checked when the whole
        // group/page is selected, indeterminate when only some rows are.
        document.querySelectorAll('.dbbackup-group-select').forEach(function (groupCb) {
            var rows = document.querySelectorAll('.dbbackup-row-check[data-group="' + groupCb.dataset.group + '"]');
            var checkedRows = document.querySelectorAll('.dbbackup-row-check[data-group="' + groupCb.dataset.group + '"]:checked');
            groupCb.checked = rows.length > 0 && checkedRows.length === rows.length;
            groupCb.indeterminate = checkedRows.length > 0 && checkedRows.length < rows.length;
        });
        var selectAllCb = document.getElementById('dbbackup-select-all');
        if (selectAllCb) {
            var allRows = document.querySelectorAll('.dbbackup-row-check');
            selectAllCb.checked = allRows.length > 0 && checked === allRows.length;
            selectAllCb.indeterminate = checked > 0 && checked < allRows.length;
        }
    }

    document.querySelectorAll('.dbbackup-row-check').forEach(function (cb) {
        cb.addEventListener('change', updateBulkBar);
    });
    var confirmBox = document.getElementById('dbbackup-bulk-confirm');
    if (confirmBox) { confirmBox.addEventListener('change', updateBulkBar); }

    var selectAll = document.getElementById('dbbackup-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.dbbackup-row-check').forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBulkBar();
        });
    }

    document.querySelectorAll('.dbbackup-group-select').forEach(function (groupCb) {
        groupCb.addEventListener('change', function () {
            document.querySelectorAll('.dbbackup-row-check[data-group="' + groupCb.dataset.group + '"]').forEach(function (cb) {
                cb.checked = groupCb.checked;
            });
            updateBulkBar();
        });
    });

    updateBulkBar();

    {if $previewBackupId}
    var restoreModalEl = document.getElementById('restoreModal');
    if (restoreModalEl && window.jQuery) { window.jQuery(restoreModalEl).modal('show'); }
    {/if}
})();
</script>

</div>
