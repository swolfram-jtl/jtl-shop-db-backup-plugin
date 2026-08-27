{* Shared "Backup starten" modal — replaces the old per-preset, easy-to-miss
   "Optionen für diesen Lauf" collapse link. Spec: every backup run (both the
   Erstellen tab and the Dashboard's quick-access buttons) should ask for a
   reason/comment (and optionally per-run overrides) via modal before
   starting, since that's more discoverable than a hidden option.
   Expects $modalId (string, must be unique — this partial is included once
   per consuming tab, and every Adminmenu tab's HTML coexists in the SAME
   page, so a shared id across tabs would collide) and $cPluginTab (string,
   this tab's exact info.xml <Customlink><Name>).
   Trigger buttons elsewhere in this tab open it via plain Bootstrap
   data-toggle="modal" + data-target="#{$modalId}", carrying the chosen
   preset as data-preset/data-preset-label — the script below reads those
   off event.relatedTarget on Bootstrap's own "show.bs.modal" event, so ONE
   modal instance serves every preset button on the page. *}
<div class="modal fade dbbackup-backup-modal" id="{$modalId}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post" action="">
                <input type="hidden" name="cPluginTab" value="{$cPluginTab|escape}">
                <input type="hidden" name="preset" class="dbbackup-modal-preset-input" value="">
                <div class="modal-header">
                    <h5 class="modal-title mb-0">
                        {d__('jtl_dbbackup_tool', 'Backup erstellen')}:
                        <span class="dbbackup-modal-preset-label font-weight-bold"></span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-2">
                        <label class="small mb-1">{d__('jtl_dbbackup_tool', 'Kommentar / Grund (optional)')}</label>
                        <input type="text" name="comment" class="form-control form-control-sm dbbackup-modal-comment"
                               maxlength="255" placeholder="{d__('jtl_dbbackup_tool', 'z. B. „vor Preis-Update Q3“')}">
                    </div>

                    <div class="form-check mb-1">
                        <input class="form-check-input dbbackup-modal-eph-toggle" type="checkbox" name="use_ephemeral_credentials" id="{$modalId}-eph">
                        <label class="form-check-label" for="{$modalId}-eph">
                            {d__('jtl_dbbackup_tool', 'FTP/SFTP-Zugangsdaten nur für diesen Lauf eingeben (nicht speichern)')}
                        </label>
                    </div>
                    <div class="dbbackup-modal-eph-fields d-flex flex-wrap mt-2 mb-3 pl-4" style="display:none; gap:.5rem;">
                        <select name="eph_protocol" class="form-control form-control-sm mb-1" style="max-width:8rem;">
                            <option value="ftps">FTPS</option>
                            <option value="sftp">SFTP</option>
                        </select>
                        <input type="text" name="eph_host" placeholder="{d__('jtl_dbbackup_tool', 'Host')}" class="form-control form-control-sm mb-1" style="max-width:12rem;">
                        <input type="text" name="eph_port" placeholder="{d__('jtl_dbbackup_tool', 'Port')}" class="form-control form-control-sm mb-1" style="max-width:6rem;">
                        <input type="text" name="eph_username" placeholder="{d__('jtl_dbbackup_tool', 'Benutzername')}" class="form-control form-control-sm mb-1" style="max-width:10rem;">
                        <input type="password" name="eph_password" placeholder="{d__('jtl_dbbackup_tool', 'Passwort')}" class="form-control form-control-sm mb-1" style="max-width:10rem;">
                    </div>

                    <div class="form-check">
                        <input class="form-check-input dbbackup-modal-enc-toggle" type="checkbox" name="encrypt_override" id="{$modalId}-enc">
                        <label class="form-check-label" for="{$modalId}-enc">
                            {d__('jtl_dbbackup_tool', 'Backup-Datei zusätzlich verschlüsseln (abweichend vom Standard)')}
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">{d__('jtl_dbbackup_tool', 'Abbrechen')}</button>
                    <button type="submit" class="btn btn-primary">{d__('jtl_dbbackup_tool', 'Backup jetzt starten')}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{* {literal}...{/literal}: see history.tpl's script block for why — Smarty's
   own delimiters are also "{"/"}". No Smarty variables are needed inside
   this block (everything is read from data-* attributes at runtime), so
   wrapping all of it is safe. Uses jQuery (already required for Bootstrap's
   own modal plugin, and already relied on elsewhere in this plugin — see
   history.tpl's restore-modal script) rather than native addEventListener,
   since Bootstrap 4's modal events are jQuery plugin events. Delegated on
   document so this works regardless of how many .dbbackup-backup-modal
   instances exist across tabs. *}
<script>
{literal}
if (window.jQuery) {
    window.jQuery(document).on('show.bs.modal', '.dbbackup-backup-modal', function (event) {
        var $modal = window.jQuery(this);
        var $trigger = window.jQuery(event.relatedTarget);
        $modal.find('.dbbackup-modal-preset-input').val($trigger.data('preset'));
        $modal.find('.dbbackup-modal-preset-label').text($trigger.data('presetLabel'));
        // Reset per-run fields on every open — this is a shared instance,
        // a previous preset's typed comment/overrides must never carry over.
        $modal.find('.dbbackup-modal-comment').val('');
        $modal.find('.dbbackup-modal-eph-toggle').prop('checked', false);
        $modal.find('.dbbackup-modal-eph-fields').hide().find('input').val('');
        $modal.find('.dbbackup-modal-enc-toggle').prop('checked', false);
    });
    window.jQuery(document).on('change', '.dbbackup-modal-eph-toggle', function () {
        window.jQuery(this).closest('.modal-body').find('.dbbackup-modal-eph-fields').toggle(this.checked);
    });
}
{/literal}
</script>
