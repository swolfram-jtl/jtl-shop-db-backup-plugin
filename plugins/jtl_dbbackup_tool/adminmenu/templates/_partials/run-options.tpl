{* Per-preset "Optionen für diesen Lauf" panel. Expects $presetKey.
   Spec decision "Ephemere Zugangsdaten": REPLACES the stored FTP/SFTP target
   for this one run only, never persisted — needs its own host/port/protocol/
   user/password fields since it can't fall back to what's already saved
   (that's the whole point). Password auth only here (no private-key
   textarea) to keep the inline form usable; the stored config still
   supports SFTP keys for the regular/scheduled path. *}
<div class="collapse" id="opts-{$presetKey}">
    <div class="card-body border-top bg-light">
        <form method="post" action="">
            <input type="hidden" name="cPluginTab" value="Backup jetzt">
            <input type="hidden" name="preset" value="{$presetKey|escape}">

            <div class="form-group mb-2">
                <label for="comment-{$presetKey}" class="small mb-1">{d__('jtl_dbbackup_tool', 'Kommentar (optional)')}</label>
                <input type="text" name="comment" id="comment-{$presetKey}" class="form-control form-control-sm"
                       maxlength="255" placeholder="{d__('jtl_dbbackup_tool', 'z. B. „vor Preis-Update Q3“')}">
            </div>

            <div class="form-check mb-1">
                <input class="form-check-input dbbackup-eph-toggle" type="checkbox" name="use_ephemeral_credentials"
                       id="eph-{$presetKey}" onchange="document.getElementById('eph-fields-{$presetKey}').style.display = this.checked ? 'flex' : 'none';">
                <label class="form-check-label" for="eph-{$presetKey}">
                    {d__('jtl_dbbackup_tool', 'FTP/SFTP-Zugangsdaten nur für diesen Lauf eingeben (nicht speichern)')}
                </label>
                <i class="fal fa-info-circle text-muted ml-1 dbbackup-info-icon"
                   title="Ersetzt für diesen einen Lauf komplett das gespeicherte FTP/SFTP-Ziel aus den Einstellungen — die hier eingegebenen Zugangsdaten werden nirgends gespeichert und gelten nur für dieses Backup. Ohne diese Angaben unten wird stattdessen das gespeicherte Ziel verwendet."></i>
            </div>

            <div id="eph-fields-{$presetKey}" class="flex-wrap mt-2 mb-3 pl-4" style="display:none; gap:.5rem;">
                <select name="eph_protocol" class="form-control form-control-sm mb-1" style="max-width:8rem;">
                    <option value="ftps">FTPS</option>
                    <option value="sftp">SFTP</option>
                </select>
                <input type="text" name="eph_host" placeholder="{d__('jtl_dbbackup_tool', 'Host')}" class="form-control form-control-sm mb-1" style="max-width:12rem;">
                <input type="text" name="eph_port" placeholder="{d__('jtl_dbbackup_tool', 'Port')}" class="form-control form-control-sm mb-1" style="max-width:6rem;">
                <input type="text" name="eph_username" placeholder="{d__('jtl_dbbackup_tool', 'Benutzername')}" class="form-control form-control-sm mb-1" style="max-width:10rem;">
                <input type="password" name="eph_password" placeholder="{d__('jtl_dbbackup_tool', 'Passwort')}" class="form-control form-control-sm mb-1" style="max-width:10rem;">
            </div>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="encrypt_override" id="enc-{$presetKey}">
                <label class="form-check-label" for="enc-{$presetKey}">
                    {d__('jtl_dbbackup_tool', 'Backup-Datei zusätzlich verschlüsseln (abweichend vom Standard)')}
                </label>
                <i class="fal fa-info-circle text-muted ml-1 dbbackup-info-icon"
                   title="Überschreibt für diesen Lauf die Standard-Einstellung. Verschlüsselt die Backup-Datei mit dem in den Einstellungen hinterlegten Passwort (XChaCha20-Poly1305) — ohne dieses Passwort ist die Datei später nicht mehr lesbar."></i>
            </div>

            <button type="submit" class="btn btn-outline-primary btn-sm">{d__('jtl_dbbackup_tool', 'Mit diesen Optionen sichern')}</button>
        </form>
    </div>
</div>
