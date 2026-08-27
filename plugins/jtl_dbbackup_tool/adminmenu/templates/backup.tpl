{include file="`$tplDir`/_partials/style.tpl"}
{* Spec: "Backup-Klick-Flow" — clicking a preset opens a modal asking for a
   comment/reason (and optional per-run overrides) before starting, rather
   than starting immediately with an easy-to-miss "Optionen für diesen Lauf"
   link — see _partials/backup-options-modal.tpl for the modal itself.
   Variables assigned by Controller\BackupController::render():
     $presets (array<string,string> key=>label),
     $flashMessage (string|null), $flashSuccess (bool)
   The modal's own form submits with action="" (see below on why: every
   Adminmenu tab file gets executed on each request to pre-render all tabs,
   so a relative action keeps the submit on whichever URL is actually
   loaded rather than guessing at a cross-tab URL scheme) and carries a
   hidden cPluginTab="Erstellen" field (tab renamed from "Backup jetzt" —
   the button labels below keep saying "Backup jetzt" on purpose, that's an
   action verb, not the tab name) — see dashboard.tpl's header comment for
   why: without it, a POST from this tab bounces back to the Dashboard tab
   on reload. *}
<div class="dbbackup-page">

{include file="`$tplDir`/_partials/flash.tpl"}

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
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#backup-modal-erstellen"
                    data-preset="full" data-preset-label="{d__('jtl_dbbackup_tool', 'Komplett')|escape}">
                {d__('jtl_dbbackup_tool', 'Backup jetzt')}
            </button>
        </div>
    </div>
</div>

{foreach $presets as $presetKey => $presetLabel}
<div class="card dbbackup-preset-card shadow-sm mb-3">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center">
            <div class="dbbackup-icon-circle bg-light text-primary mr-3"><i class="fal fa-file-export"></i></div>
            <div>{$presetLabel|escape}</div>
        </div>
        <div class="mt-2 mt-md-0">
            <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#backup-modal-erstellen"
                    data-preset="{$presetKey|escape}" data-preset-label="{$presetLabel|escape}">
                {d__('jtl_dbbackup_tool', 'Backup jetzt')}
            </button>
        </div>
    </div>
</div>
{/foreach}

{include file="`$tplDir`/_partials/backup-options-modal.tpl" modalId="backup-modal-erstellen" cPluginTab="Erstellen"}

</div>
