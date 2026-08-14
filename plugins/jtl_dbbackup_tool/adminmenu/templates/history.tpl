{include file="`$tplDir`/_partials/style.tpl"}
{* Spec: "UI-Komponenten-Reuse" (pagination.tpl/$oBlaetterNavi — TODO once
   Settingslink/pagination wiring specifics are confirmed, plain markup for
   now), "Restore-Vorschau" (row-count diff, unchanged tables collapsed),
   "Restore-Confirm-UX" (fixed type-to-confirm text "RESTORE").
   Variables assigned by Controller\HistoryController::render():
     $backups (array of: id, dCreated, cLabel, cStatus, nSizeBytes, bEncrypted),
     $flashMessage (string|null), $flashSuccess (bool),
     $previewBackupId (string|null), $previewDiff (array|null: table=>[before,after]),
     $previewWarnings (string[]|null), $previewVersionMismatch (bool)
   Links/forms use query-relative ("?action=...") / action="" targets so they
   resolve against whatever URL is currently loaded. Download/Restore are
   only offered for cStatus === 'ok' — a failed run has nothing usable to
   download or restore from. Every form also carries a hidden
   cPluginTab="Wiederherstellen" field — see dashboard.tpl's header comment
   for why: without it, a POST from this tab (e.g. a failed confirmation-word
   retry, or the restore preview step) bounces back to the Dashboard tab on
   reload, which was the exact bug reported against this tab. *}
<div class="dbbackup-page">

{if $flashMessage}
<div class="alert alert-dismissible {if $flashSuccess}alert-success{else}alert-danger{/if} shadow-sm">
    {$flashMessage|escape}
    <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
</div>
{/if}

<div class="card shadow-sm">
<table class="table table-striped mb-0">
    <thead>
        <tr>
            <th>{d__('jtl_dbbackup_tool', 'Erstellt')}</th>
            <th>{d__('jtl_dbbackup_tool', 'Bezeichnung')}</th>
            <th>{d__('jtl_dbbackup_tool', 'Status')}</th>
            <th>{d__('jtl_dbbackup_tool', 'Größe')}</th>
            <th>{d__('jtl_dbbackup_tool', 'Verschlüsselt')}</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    {foreach $backups as $backup}
        <tr>
            <td>{$backup.dCreated|escape}</td>
            <td>{$backup.cLabel|escape}</td>
            <td><span class="badge {if $backup.cStatus === 'ok'}badge-success{else}badge-danger{/if}">{$backup.cStatus|escape}</span></td>
            <td>{$backup.nSizeBytes|string_format:"%.1f"} MB</td>
            <td>{if $backup.bEncrypted}{d__('jtl_dbbackup_tool', 'Ja')}{else}{d__('jtl_dbbackup_tool', 'Nein')}{/if}</td>
            <td class="text-right">
            {if $backup.cStatus === 'ok' && $backup.nSizeBytes > 0}
                <a href="?action=download&amp;id={$backup.id|escape}" class="btn btn-sm btn-outline-secondary">{d__('jtl_dbbackup_tool', 'Download')}</a>
                <form method="post" action="" class="d-inline">
                    <input type="hidden" name="cPluginTab" value="Wiederherstellen">
                    <input type="hidden" name="action" value="preview">
                    <input type="hidden" name="id" value="{$backup.id|escape}">
                    {if $backup.bEncrypted}
                    <input type="password" name="decryption_passphrase" placeholder="{d__('jtl_dbbackup_tool', 'Passwort')}" class="form-control form-control-sm d-inline-block" style="width: 8rem;">
                    {/if}
                    <button type="submit" class="btn btn-sm btn-outline-danger">{d__('jtl_dbbackup_tool', 'Wiederherstellen…')}</button>
                </form>
            {else}
                <span class="text-muted small">{d__('jtl_dbbackup_tool', 'nicht verfügbar')}</span>
            {/if}
            </td>
        </tr>
    {foreachelse}
        <tr><td colspan="6" class="text-muted">{d__('jtl_dbbackup_tool', 'Noch keine Backups vorhanden.')}</td></tr>
    {/foreach}
    </tbody>
</table>
</div>

{if $previewBackupId}
<div class="card border-danger shadow mt-4">
    <div class="card-header bg-danger text-white"><i class="fal fa-exclamation-triangle mr-2"></i>{d__('jtl_dbbackup_tool', 'Wiederherstellung vorbereiten — dieser Vorgang überschreibt aktuelle Daten')}</div>
    <div class="card-body">

        {if $previewVersionMismatch}
        <div class="alert alert-warning">
            <strong>Tabellenstruktur weicht vom Backup-Zeitpunkt ab</strong> (Shop-Update oder andere Migration seitdem).
            Eine Wiederherstellung könnte inkonsistente Daten erzeugen.
        </div>
        {/if}

        {if $previewWarnings}
        <div class="alert alert-warning">
            <strong>Mögliche verwaiste Datensätze nach dieser Wiederherstellung:</strong>
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
                    <input type="hidden" name="cPluginTab" value="Wiederherstellen">
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
                <label>Zum Bestätigen <code>RESTORE</code> eintippen:</label>
                <input type="text" name="confirmation" class="form-control" autocomplete="off" required>
            </div>
            <button type="submit" class="btn btn-danger">{d__('jtl_dbbackup_tool', 'Jetzt wiederherstellen')}</button>
        </form>
    </div>
</div>
{/if}

</div>
