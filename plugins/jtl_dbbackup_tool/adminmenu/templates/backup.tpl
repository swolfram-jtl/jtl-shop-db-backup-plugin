{include file="`$tplDir`/_partials/style.tpl"}
{* Spec: "Backup-Klick-Flow" — one click per preset with configured defaults,
   plus an "Optionen für diesen Lauf" disclosure for per-run overrides.
   Variables assigned by Controller\BackupController::render():
     $presets (array<string,string> key=>label),
     $flashMessage (string|null), $flashSuccess (bool)
   Forms submit with action="" (see below on why: every Adminmenu tab file
   gets executed on each request to pre-render all tabs, so a relative
   action keeps the submit on whichever URL is actually loaded rather than
   guessing at a cross-tab URL scheme). Every form (here and in
   _partials/run-options.tpl) also carries a hidden cPluginTab="Backup jetzt"
   field — see dashboard.tpl's header comment for why: without it, a POST
   from this tab bounces back to the Dashboard tab on reload. *}
<div class="dbbackup-page">

{if $flashMessage}
<div class="alert alert-dismissible {if $flashSuccess}alert-success{else}alert-danger{/if} shadow-sm" role="alert">
    {$flashMessage|escape}
    <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
</div>
{/if}

<p class="text-muted mb-4">{d__('jtl_dbbackup_tool', 'Presets sind exakt wie im Shop-Backend-Menü benannt, damit klar ist, welches Backup zu welcher Import-Funktion gehört.')}</p>

<div class="card dbbackup-preset-card dbbackup-preset-card--full border-primary shadow-sm mb-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center">
            <div class="dbbackup-icon-circle bg-primary text-white mr-3"><i class="fal fa-database"></i></div>
            <div>
                <div class="font-weight-bold">{d__('jtl_dbbackup_tool', 'Komplett')}</div>
                <div class="text-muted small">{d__('jtl_dbbackup_tool', 'Vollständige Datenbank (ohne Plugin-eigene Tabellen)')}</div>
            </div>
        </div>
        <div class="mt-2 mt-md-0">
            <form method="post" action="" class="d-inline">
            <input type="hidden" name="cPluginTab" value="Backup jetzt">
                <input type="hidden" name="preset" value="full">
                <button type="submit" class="btn btn-primary">{d__('jtl_dbbackup_tool', 'Backup jetzt')}</button>
            </form>
            <a class="btn btn-link" data-toggle="collapse" href="#opts-full">{d__('jtl_dbbackup_tool', 'Optionen für diesen Lauf')}</a>
        </div>
    </div>
    {include file="`$tplDir`/_partials/run-options.tpl" presetKey="full"}
</div>

{foreach $presets as $presetKey => $presetLabel}
<div class="card dbbackup-preset-card shadow-sm mb-3">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center">
            <div class="dbbackup-icon-circle bg-light text-primary mr-3"><i class="fal fa-file-export"></i></div>
            <div>{$presetLabel|escape}</div>
        </div>
        <div class="mt-2 mt-md-0">
            <form method="post" action="" class="d-inline">
            <input type="hidden" name="cPluginTab" value="Backup jetzt">
                <input type="hidden" name="preset" value="{$presetKey|escape}">
                <button type="submit" class="btn btn-primary btn-sm">{d__('jtl_dbbackup_tool', 'Backup jetzt')}</button>
            </form>
            <a class="btn btn-link btn-sm" data-toggle="collapse" href="#opts-{$presetKey}">{d__('jtl_dbbackup_tool', 'Optionen für diesen Lauf')}</a>
        </div>
    </div>
    {include file="`$tplDir`/_partials/run-options.tpl" presetKey=$presetKey}
</div>
{/foreach}

</div>
