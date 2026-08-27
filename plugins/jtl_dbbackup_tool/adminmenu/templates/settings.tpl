{include file="`$tplDir`/_partials/style.tpl"}
{* Custom "Einstellungen" tab — see Controller\SettingsPageController's own
   docblock for the full why/how. Settings live in this plugin's OWN table
   now (SettingsStore) — the native <Settingslink> form/schema this tab
   originally reused for persistence is gone entirely (that's what made
   removing the "Erweiterte Einstellungen (Rohformular)" fallback tab
   possible; it could never be removed while anything still depended on its
   <Setting> schema). This tab does its own CSRF check (Form::validateToken(),
   against the same $jtlToken below) and its own save logic.
   Variables assigned by Controller\SettingsPageController::render():
     $jtlToken (string, raw <input> HTML from JTL\Helpers\Form::getTokenInput()),
     $flashMessage (string|null), $flashSuccess (bool),
     $connectionTestResult (array{ok,message}|null),
     $sections (array of {title, description?, connectionTest?, fields: [...]})
       field shapes:
         text: {type, name, label, description, value}
         number: {type, name, label, description, value, revealedBy?}
         checkbox: {type, name, label, description, checked, revealsField?}
         encrypted: {type, name, label, description, hasValue, revealedBy?}
         select: {type, name, label, description, options, value}
         checkboxGroup: {type, name, label, description, options, selected}
   This form POSTs to action="" like every other form in this plugin — see
   dashboard.tpl's header comment for why (every Adminmenu tab file executes
   on every request; a relative action keeps the submit on whichever URL is
   actually loaded).
   Single form, two submit buttons ("Speichern" / "Speichern und Verbindung
   testen" — spec: like the shop's own mail-server settings) — fixed a real
   reported bug where a SEPARATE test-connection form (posting none of the
   actual field values) reloaded the page and showed the host field's last-
   SAVED value as if it had been cleared. See Controller\SettingsController::
   handleConnectionTest()'s docblock for the full root cause. *}
<div class="dbbackup-page">

{include file="`$tplDir`/_partials/flash.tpl"}

{if $connectionTestResult}
<div class="alert alert-dismissible {if $connectionTestResult.ok}alert-success{else}alert-danger{/if} shadow-sm">
    {$connectionTestResult.message|escape}
    <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
</div>
{/if}

<form method="post" action="" id="dbbackup-settings-form">
    <input type="hidden" name="cPluginTab" value="Einstellungen">
    {$jtlToken}
    <input type="hidden" name="save_settings" value="1">

    {foreach $sections as $section}
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="mb-1">{$section.title|escape}</h5>
            {if $section.description}<p class="dbbackup-section-description mb-3">{$section.description|escape}</p>{/if}

            {foreach $section.fields as $field}
            <div class="form-group form-row align-items-start mb-3 dbbackup-setting-row" {if $field.revealedBy}data-revealed-by="{$field.revealedBy|escape}" style="display:none;"{/if}>
                <div class="col-sm-3">
                    <label class="col-form-label pb-0 mb-0" for="{$field.name}">{$field.label|escape}</label>
                    {if $field.description}<div class="dbbackup-field-description">{$field.description|escape}</div>{/if}
                </div>
                <div class="col-sm-9">
                    {if $field.type === 'text'}
                        <input type="text" class="form-control" id="{$field.name}" name="{$field.name}" value="{$field.value|escape}">
                    {elseif $field.type === 'number'}
                        <input type="number" class="form-control" id="{$field.name}" name="{$field.name}" value="{$field.value|escape}" style="max-width:10rem;">
                    {elseif $field.type === 'checkbox'}
                        <div class="form-check">
                            <input class="form-check-input dbbackup-setting-checkbox" type="checkbox" id="{$field.name}" name="{$field.name}"
                                   {if $field.checked}checked{/if} {if $field.revealsField}data-reveals="{$field.revealsField|escape}"{/if}>
                            <label class="form-check-label" for="{$field.name}"></label>
                        </div>
                    {elseif $field.type === 'encrypted'}
                        <input type="password" class="form-control" id="{$field.name}" name="{$field.name}" value="" autocomplete="new-password"
                               placeholder="{if $field.hasValue}{d__('jtl_dbbackup_tool', '•••• (gespeichert — zum Ändern neu eingeben)')}{else}{d__('jtl_dbbackup_tool', 'nicht gesetzt')}{/if}">
                    {elseif $field.type === 'select'}
                        <select class="form-control" id="{$field.name}" name="{$field.name}" style="max-width:14rem;">
                            {foreach $field.options as $optValue => $optLabel}
                            <option value="{$optValue|escape}" {if $field.value === $optValue}selected{/if}>{$optLabel|escape}</option>
                            {/foreach}
                        </select>
                    {elseif $field.type === 'checkboxGroup'}
                        <input type="hidden" name="{$field.name}" id="{$field.name}-value" value="{$field.selectedCsv|escape}">
                        <div class="d-flex flex-wrap" style="gap:.4rem .9rem;">
                        {foreach $field.options as $optValue => $optLabel}
                            <div class="form-check">
                                <input class="form-check-input dbbackup-cron-preset-checkbox" type="checkbox" id="{$field.name}-{$optValue}"
                                       data-preset="{$optValue|escape}" {if in_array($optValue, $field.selected, true)}checked{/if}>
                                <label class="form-check-label" for="{$field.name}-{$optValue}">{$optLabel|escape}</label>
                            </div>
                        {/foreach}
                        </div>
                    {/if}
                </div>
            </div>
            {/foreach}

            {if $section.connectionTest}
            <div class="mt-2">
                <button type="submit" name="test_connection" value="1" class="btn btn-secondary">
                    {d__('jtl_dbbackup_tool', 'Speichern und Verbindung testen')}
                </button>
                <span class="small text-muted ml-2">{d__('jtl_dbbackup_tool', 'Speichert die obigen Felder und prüft danach sofort Login und Schreibrechte.')}</span>
            </div>
            {/if}
        </div>
    </div>
    {/foreach}

    <button type="submit" class="btn btn-primary">
        <i class="fal fa-save mr-1"></i>{d__('jtl_dbbackup_tool', 'Speichern')}
    </button>
</form>

{* {literal}...{/literal}: see history.tpl's script block for why — Smarty's
   own delimiters are also "{"/"}", plain JS braces can be mis-parsed as
   malformed Smarty tags otherwise. No Smarty variables are needed inside
   this block, so wrapping all of it is safe. *}
<script>
{literal}
(function () {
    function applyReveal(checkboxName, revealed) {
        document.querySelectorAll('.dbbackup-setting-row[data-revealed-by="' + checkboxName + '"]').forEach(function (row) {
            row.style.display = revealed ? '' : 'none';
        });
    }

    document.querySelectorAll('.dbbackup-setting-checkbox[data-reveals]').forEach(function (cb) {
        applyReveal(cb.id, cb.checked);
        cb.addEventListener('change', function () { applyReveal(cb.id, cb.checked); });
    });

    var cronHidden = document.getElementById('cron_backup_presets-value');
    function syncCronPresets() {
        if (!cronHidden) { return; }
        var selected = [];
        document.querySelectorAll('.dbbackup-cron-preset-checkbox:checked').forEach(function (cb) {
            selected.push(cb.dataset.preset);
        });
        cronHidden.value = selected.join(',');
    }
    document.querySelectorAll('.dbbackup-cron-preset-checkbox').forEach(function (cb) {
        cb.addEventListener('change', syncCronPresets);
    });
    var settingsForm = document.getElementById('dbbackup-settings-form');
    if (settingsForm) { settingsForm.addEventListener('submit', syncCronPresets); }
})();
{/literal}
</script>

</div>
