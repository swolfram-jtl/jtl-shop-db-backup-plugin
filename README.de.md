# DB Backup Manager für JTL-Shop 5.8

Ein JTL-Shop-5.8-Plugin, mit dem Shop-Betreiber gezielte oder vollständige
Datenbank-Backups anlegen können — lokal und/oder auf ein FTPS/SFTP-Ziel —,
sie organisieren, kommentieren und löschen können, und sie wieder einspielen
können, damit ein Fehler bei einer der eingebauten CSV-Import-Funktionen des
Shops (Kunden, Newsletter-Empfänger, PLZ/Ort, Weiterleitungen, Gutscheine,
Bewertungen, Sprachvariablen) schnell rückgängig gemacht werden kann. Der
**Backups**-Tab ist ein vollständiger Manager: Backups sind nach Preset/Typ
gruppiert, filter- und sortierbar, einzeln oder als Mehrfachauswahl löschbar
(nur die lokale Kopie — siehe „Bekannte Lücken" unten), und können einen
Freitext-Kommentar tragen (bei Anlage gesetzt oder jederzeit nachträglich
editiert), der dokumentiert, *warum* ein Backup existiert.

Umbenannt von „Database Export Import Backup Tool" — die PluginID
(`jtl_dbbackup_tool`) bleibt unverändert, das ist also nur eine
Anzeige-/Doku-Umbenennung, kein Breaking Change für eine bereits installierte
Instanz.

English version: [README.md](README.md)

> **Status:** installiert und lauffähig gegen eine echte
> JTL-Shop-5.8.0-rc3-Instanz — Dashboard, Backup jetzt, Backups (Manager +
> Restore) und Einstellungen rendern alle, der manuelle Backup-Ablauf
> funktioniert Ende-zu-Ende. In aktiver Iteration gegen echten Einsatz; siehe
> `CHANGELOG.md` für das laufende Protokoll gefundener und behobener Bugs
> (mehrere — ein Doppel-Trigger-Bug, ein falscher Rückgabetyp, eine
> Ephemere-Zugangsdaten-UI, die zuvor ein No-Op war, ein
> Restore-Lock-Selbst-Deadlock — siehe `Service/RequestGuard.php`s Docblock
> für einen besonders unauffälligen: **jede Adminmenu-Tab-PHP-Datei läuft bei
> JEDER einzelnen Anfrage**, nicht nur beim sichtbaren Tab, was für jeden
> künftigen Controller relevant ist, der `$_POST` liest). Die Architektur und
> ~56 einzelne Design-Entscheidungen dahinter wurden in einer eigenen
> Design-Review erarbeitet, siehe `docs/architecture-spec.html`.

## Was das ist (und was nicht)

- **Ist**: ein Sicherheitsnetz für die Datenbank-Änderungen, die die
  CSV-Import-Funktionen des Shops selbst vornehmen.
- **Ist nicht**: ein eigener CSV-Importer, ein Datei-/Template-Backup-Tool,
  oder ein Shop-Klon-/Staging-Migrations-Werkzeug.

## Projektstruktur

```
plugins/<PluginID>/
  info.xml            Plugin-Manifest (Adminmenü-Tabs, Einstellungsfelder)
  Bootstrap.php        Plugin-Lebenszyklus (Installation/Aktivierung, Cron-Registrierung)
  Service/             Backup-, Restore-, Preset-, Storage-, Lock-, Manifest-,
                        Verschlüsselungs-, Retention-, Benachrichtigungs-,
                        Settings- und FTPS/SFTP-Upload-Services
  Cron/                Wiederkehrender geplanter Backup-Job
  Controller/          Backend-Tab-Controller (Dashboard, Backup, Backups/Manager (Klasse
                        heißt weiterhin HistoryController — siehe deren Docblock), Einstellungen)
  Migrations/           Eigene Schema-Migrationen (Audit-Log, Backup-Historie)
  adminmenu/            Adminmenü-<Customlink>-Einstiegspunkte + deren Templates
                        (verifizierter Ort: PFAD_PLUGIN_ADMINMENU = 'adminmenu/')
  templates/settings.tpl
                        Einstellungen-Tab-Zusatz (Verbindungstest-Button) — der
                        Render-/Hook-Mechanismus von Settingslink selbst ist
                        NOCH NICHT verifiziert, siehe „Bekannte Lücken"
```

`<PluginID>` ist aktuell der Platzhalter `jtl_dbbackup_tool` — vor einer
echten Installation umbenennen (inkl. Ordner `plugins/jtl_dbbackup_tool/`
und `namespace Plugin\jtl_dbbackup_tool` in jeder PHP-Datei).

## Einrichtung

FTPS-Backups funktionieren sofort (natives PHP-`ext-ftp`, ohnehin schon
Core-Voraussetzung). **SFTP** braucht eine echte, geprüfte SSH-Bibliothek
statt einer selbstgeschriebenen Protokoll-Implementierung — dieses Plugin
bringt dafür eine eigene `composer.json` mit:

```bash
cd plugins/jtl_dbbackup_tool
composer install --no-dev
```

Kann übersprungen werden, wenn nur lokale Backups und/oder FTPS gebraucht
werden — SFTP-Ziele melden dann einen klaren Einrichtungshinweis statt eines
rohen Fatal Errors, falls `vendor/` fehlt.

## Was implementiert ist

Jede Kernentscheidung aus `docs/architecture-spec.html` steckt als echte
Implementierung dahinter, nicht als Stub — Backup (alle 7 Presets +
„Komplett"), automatischer Restore mit vollständigem Sicherheitsnetz
(Pflicht-Vorab-Snapshot, Versions-/Struktur-Fingerprint-Check, Best-Effort-
Konsistenzprüfung, Type-to-Confirm), atomares Schreiben, Backup-Selbsttest,
Nebenläufigkeits-Sperre, Speicherplatz-Vorabprüfung, optionale
XChaCha20-Poly1305-Verschlüsselung, FTPS/SFTP-Upload inkl. ephemerer
Zugangsdaten-Option, Retention-/Rotationsregel, wiederkehrender Cron-Job,
ein Audit-Log, das strukturell vom eigenen Restore-Umfang ausgeschlossen
ist, sowie eine Dashboard-/Backup-/Manager-Oberfläche mit echten
Smarty-Templates. Der **Backups**-Tab bietet zusätzlich: Gruppierung nach
Preset mit Mehrfachauswahl pro Gruppe, Filtern (Preset/Status/Speicherort)
und Sortieren (Datum/Größe/Status), echte serverseitige Pagination,
Freitext-Kommentare (bei Anlage setzbar, jederzeit inline editierbar),
Einzel- und Mehrfach-Löschen (lokale Datei + Manifest + Historie-Zeile —
abgesichert durch Bestätigungsdialog/-Checkbox und gesperrt, während ein
Backup oder Restore läuft), sowie den Restore-Vorschau/Bestätigen-Ablauf in
einem Modal.

## Bekannte Lücken — vor Produktiveinsatz prüfen

Nichts hiervon konnte in der Umgebung, in der es entstand, tatsächlich
ausgeführt werden (keine PHP-Laufzeit, kein Shop) — das ist eine sorgfältige
Best-Effort-Implementierung gegen verifizierte Core-APIs, wo immer möglich,
aber noch kein einziges Mal gelaufen. Vor echtem Vertrauen mit echten Daten:

**Wahrscheinlich bei der ersten Installation zu korrigieren:**
- `Service/BackupService.php` und `RestoreService.php`s `buildDsn()` gehen
  von `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`-Konstanten aus (klassische
  JTL-Shop-Konvention), um mysqldump-phps eigene, separate DB-Verbindung zu
  öffnen — nicht gegen `config.JTL-Shop.ini.php` dieser Shop-Version
  verifiziert.
- `Cron/BackupCronJob.php` geht davon aus, dass
  `JTL\Plugin\Helper::getLoaderByPluginID()` der Weg ist, wie ein Plugin
  seine eigene Instanz aus einem Cron-Kontext lädt (anders als bei
  Customlink-Dateien gibt es dort kein automatisch bereitgestelltes
  `$oPlugin`) — unverifiziert. Falls das nicht stimmt, ist NUR das
  wiederkehrende geplante Backup betroffen; der manuelle „Backup
  jetzt"-Ablauf hängt nicht davon ab.
- Die ID des aktuell eingeloggten Admins wird als
  `$_SESSION['AdminAccount']->kAdminlogin` gelesen (fürs Audit-Log) —
  Eigenschaftsname unverifiziert.
- `ftp_protocol`s `<Setting type="selectbox">` braucht irgendeine
  Options-Liste (eine `<Option>`/`<Value>`-Kindstruktur wurde nicht
  bestätigt) — aktuell ist nur `initialValue="ftps"` gesetzt.

**Bewusste Scope-Entscheidungen, keine Bugs:**
- Klicks auf „Backup jetzt" laufen **synchron** im Admin-Request, nicht über
  die Cron-Queue, obwohl der Spec Letzteres vorsah („Lange Backups laufen
  immer async") — die genaue `CronController::addQueueEntry()`-API zum
  Einreihen eines parametrisierten Einmal-Jobs wurde nicht rechtzeitig
  verifiziert. In der Praxis unproblematisch für jedes Preset (kleine
  Tabellen); „Komplett" bei einer sehr großen Datenbank könnte ein
  PHP-Request-Timeout auslösen. Der wiederkehrende Cron-Job
  (`BackupCronJob`) ist davon nicht betroffen.
- Die Verbindungstest-**Logik** (`SettingsController::handleConnectionTest()`)
  ist fertig, aber noch nicht ins Settingslink-gerenderte Formular
  eingehängt (siehe Projektstruktur oben).
- Nur das Audit-Log (nicht der Backups-Tab, der jetzt echte Pagination hat)
  ist noch eine einfache, unpaginierte Tabelle statt der Core-Komponente
  `pagination.tpl`/`$oBlaetterNavi` — dafür gibt es bislang gar keine eigene
  Admin-Oberfläche.
- Das Löschen eines Backups entfernt IMMER nur die **lokale** Kopie (Datei +
  Manifest + Historie-Zeile) — eine bewusste Entscheidung, kein Versehen:
  FTP/SFTP ist als unabhängige Offsite-Sicherheitskopie gedacht, ein
  einzelner Löschen-Klick darf niemals beide Kopien gleichzeitig vernichten
  können. Es gibt aktuell keine UI, um eine Remote-Kopie zu löschen (bräuchte
  eine neue `delete()`-Methode auf `UploadTargetInterface`, nicht
  implementiert).
- Die Shop-Instanz-Kennung (`ManifestService::instanceId()`) wird aus
  `Shop::getURL()` abgeleitet, mit `method_exists()` abgesichert — fällt auf
  einen statischen `'unknown-instance'`-String zurück (nicht pro Installation
  gespeichert), falls diese Methode nicht existiert, was den
  Multi-Shop-Kollisionsschutz und die Cross-Instanz-Restore-Sperre
  abschwächen (nicht brechen) würde.

**Gar nicht umgesetzt:**
- Keine automatisierten Tests (der Spec sieht eine echte
  Backup+Restore-Roundtrip-Testsuite gegen eine echte Test-Shop-Instanz als
  Release-Gate vor — dafür muss diese Testinstanz erst existieren).
- Kein Haftungsausschluss-Text für Endnutzer.

## Gegen den Shop-Core verifiziert (release/5.8.0)

Mehrere Recherche-Runden gegen das öffentliche Core-Repo haben unterwegs
falsche Erst-Annahmen korrigiert — das echte `info.xml`-Schema
(`<XMLVersion>`, `<Setting>`-Attribute, `type="encrypted"` funktioniert
tatsächlich, obwohl die Doku es weglässt, `<MinShopVersion>`-Format), die
exakten Lebenszyklus-Signaturen von `Bootstrapper`, wo `<Customlink>`-Dateien
physisch liegen müssen und was beim Ausführen im Scope ist, die echte
`JTL\DB\DbInterface`-CRUD-/Transaktions-API, wie Plugin-Migrationen
automatisch über `MigrationManager` laufen (kein manuelles Verdrahten aus
`Bootstrap.php` nötig), `JTLSmarty::assign()`/`fetch()`, und
`JTL\Plugin\Data\Config::getValue()` vs. `getDecryptedValue()` für
verschlüsselte Einstellungen. Details stehen in den Docblocks im Code direkt
bei der jeweiligen Entscheidung.

## Gegen eine echte laufende Installation + den Shop-Core-Quellcode bestätigt

Nach der Installation auf einem echten 5.8.0-rc3-Shop tauchten drei subtile
Bugs auf, die sich alle mit einer eindeutigen, quellcode-bestätigten
Ursache und Fix klären ließen (Details jeweils in `CHANGELOG.md`):

- **Tab springt nach jedem POST von einem anderen Tab zurück auf Dashboard.**
  Alle Adminmenu-Tabs werden tatsächlich in EINE Seite gerendert; welcher Tab
  *angezeigt* wird, entscheidet sich serverseitig über einen
  `kPluginAdminMenu`/`cPluginTab`-Request-Parameter, den reines
  Client-seitiges Tab-Umschalten nie setzt. Jedes Formular trägt jetzt ein
  verstecktes `cPluginTab`-Feld mit dem exakten `info.xml`-`<Name>` seines
  Tabs.
- **`conf="N"`-Abschnittsüberschriften zeigten einen rohen internen
  Schlüssel statt echtem Text.** Die Überschrift einer nicht konfigurierbaren
  Einstellung ist ihr `<Name>`-Element, nicht `<Description>` oder
  `initialValue` — das war vertauscht.
- **Checkbox-Einstellungen lasen sich nie als aktiviert, obwohl
  `initialValue="Y"` gesetzt war und die Box angehakt aussah.** Die echte
  Checkbox-Konvention von JTL-Shop ist `"on"`/`NULL`, nicht `"Y"`/`"N"` — es
  gab von Anfang an keine echte Y/N-Konvention hierfür. Behoben in `info.xml`
  und `SettingsRepository::checkbox()`. Bei einer bereits installierten
  älteren Version: die betroffenen Checkboxen einmal im Einstellungen-Tab neu
  anhaken und speichern — ein Plugin-Update schreibt bereits gespeicherte
  Konfigurationswerte nicht rückwirkend um.
- **Restore schlug immer fehl mit „Es läuft bereits ein Backup oder
  Restore".** `RestoreService::restore()` holt sich die Datei-Sperre des
  Plugins, ruft dann — wenn die Vorab-Snapshot-Option aktiv ist (Standard) —
  `BackupService::createBackup()` auf, die sich über eine eigene, separat
  konstruierte `LockService`-Instanz DIESELBE Sperr-Datei erneut zu holen
  versucht. `flock()` hängt am offenen Datei-Handle, nicht am Prozess — ein
  zweites `fopen()`+`flock()` auf denselben Pfad aus demselben PHP-Prozess
  sieht die eigene bereits gehaltene Sperre nicht und schlägt sofort fehl.
  Behoben mit prozessweiter wiedereintrittsfähiger Sperrlogik in
  `LockService` (ein pfadbasierter Tiefenzähler) — Details in dessen
  Docblock.
- **Plugin wirkte nie mehrsprachig, selbst mit Admin-Konto auf Englisch.**
  Die komplette Lade-Kette wurde gegen den echten
  `includes/src/L10n/GetText.php`/`Translator.php`-Code sowie das
  `gettext/gettext`-Paket (`Loader/MoLoader.php`,
  `Generator/ArrayGenerator.php`) neu verifiziert: Pfad
  `locale/<sprache>/base.mo` und die flache Struktur (kein `LC_MESSAGES`)
  waren bereits korrekt, und `admin/locale/` einer echten 5.8.0-Version
  bestätigt `en-GB` (nicht `en-US`, obwohl das in der öffentlichen Doku als
  Beispiel steht) als richtigen Ordnernamen. Der echte, behebbare Befund:
  `Gettext\Translator` sucht Plugin-Strings über eine Domain, und die Domain
  kommt aus einem `X-Domain`-Header INNERHALB der .po/.mo-Metadaten — ohne
  ihn kann JTL-Shop das teilweise über einen Fallback auf eine gemeinsame
  Standard-Domain kompensieren, aber das ist fragil. `base.po`/`base.mo`
  werden jetzt mit explizit gesetztem `X-Domain: jtl_dbbackup_tool` gebaut,
  und der neue .mo-Writer wurde Byte für Byte gegen `MoLoader.php`s
  tatsächliche Parser-Logik zurückverifiziert. Falls danach immer noch nicht
  übersetzt wird: prüfen, ob das Test-Admin-Konto wirklich auf Englisch
  steht, und einmalig den Sprach-Cache des Shops (`DIR_LOCALE_CACHE`) leeren
  — JTL cacht geparste .mo-→-PHP-Array-Konvertierungen anhand der
  Datei-mtime.

## Nächste Schritte

1. Auf eine echte JTL-Shop-5.8-Testinstanz bringen und beheben, was der
   Abschnitt „Bekannte Lücken" oben vorhersagt.
2. Die „Bewusste Scope-Entscheidungen"-Liste durchgehen — die meisten sind
   für ein v1 in Ordnung, aber der Async-Cron-Queue-Punkt lohnt sich zu
   überdenken, falls „Komplett"-Backups spürbar langsam werden.
3. Erst danach: echtes Backup/Restore-Roundtrip-Testing, wie es der Spec als
   QA-Anforderung vorsieht.
