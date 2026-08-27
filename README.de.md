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
> JTL-Shop-5.8.0-rc3-Instanz — Dashboard, Erstellen, Backups verwalten
> (Historie) (Manager + Restore) und Einstellungen rendern alle, der manuelle
> Backup-Ablauf
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
                        heißt weiterhin HistoryController — siehe deren Docblock), Einstellungen —
                        SettingsPageController, ein komplett eigener Tab, NICHT das native
                        <Settingslink>-Rendering — siehe „Gegen eine echte laufende
                        Installation ... bestätigt" unten)
  Migrations/           Eigene Schema-Migrationen (Audit-Log, Backup-Historie)
  adminmenu/            Adminmenü-<Customlink>-Einstiegspunkte + deren Templates
                        (verifizierter Ort: PFAD_PLUGIN_ADMINMENU = 'adminmenu/')
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

Das meiste, was hier ursprünglich als „unverifiziert, keine PHP-Laufzeit zum
Testen verfügbar" markiert war, wurde inzwischen gegen den echten
Shop-Core-Quellcode (eine lokale Kopie von release/5.8.0) oder gegen eine
echte laufende Installation geprüft — siehe „Gegen den Shop-Core verifiziert"
und „Bestätigt gegen eine echte laufende Installation" weiter unten, unter
anderem zwei bis dahin unentdeckte Bugs (der Cronjob-Typ hat sich nie
registriert, und der wiederkehrende Job ist bei jedem Lauf still an einem
`TypeError` gescheitert) — beide jetzt behoben. Was hier noch offen bleibt:

**Bewusste Scope-Entscheidungen, keine Bugs:**
- Klicks auf „Backup jetzt" laufen **synchron** im Admin-Request, nicht über
  die Cron-Queue, obwohl der Spec Letzteres vorsah („Lange Backups laufen
  immer async"). GEPRÜFT (nicht nur angenommen) gegen die echte
  `CronController::addQueueEntry(array $post): int`: sie unterstützt nur
  dieselbe wiederkehrende Zeitplan-Form (`frequency`/`startDate`/
  `startTime`, eine `tcron`-Zeile, die ab dann in ihrem eigenen Rhythmus
  läuft), die `Cron/BackupCronJob.php` bereits nutzt — es gibt in diesem
  Controller keine eigene „einmal einreihen, gleich im Hintergrund
  ausführen"-Funktion. Ein echter Einmal-Trigger bräuchte einen anderen
  Mechanismus (einen anderen Teil des `Cron\Queue`-Ausführungsmodells, nicht
  weiter untersucht) — nicht einfach einen anderen Aufruf derselben Methode.
  In der Praxis unproblematisch für jedes Preset (kleine Tabellen);
  „Komplett" bei einer sehr großen Datenbank könnte ein PHP-Request-Timeout
  auslösen. Der wiederkehrende Cron-Job ist davon so oder so nicht betroffen
  und hat inzwischen einen eigenen, unabhängigen Zeitplan nur für „Komplett"
  (`Cron/FullBackupCronJob`).
- Nur das Audit-Log (nicht der Backups-Tab, der jetzt echte Pagination hat)
  ist noch eine einfache, unpaginierte Tabelle statt einer Core-Pagination-
  Komponente — dafür gibt es bislang gar keine eigene Admin-Oberfläche.
- Das Löschen eines Backups entfernt IMMER nur die **lokale** Kopie (Datei +
  Manifest + Historie-Zeile) — eine bewusste Entscheidung, kein Versehen:
  FTP/SFTP ist als unabhängige Offsite-Sicherheitskopie gedacht, ein
  einzelner Löschen-Klick darf niemals beide Kopien gleichzeitig vernichten
  können. Es gibt aktuell keine UI, um eine Remote-Kopie zu löschen (bräuchte
  eine neue `delete()`-Methode auf `UploadTargetInterface`, nicht
  implementiert).
- Die Shop-Instanz-Kennung (`ManifestService::instanceId()`) wird aus
  `Shop::getURL()` abgeleitet, mit `method_exists()` abgesichert — BESTÄTIGT,
  dass diese Methode in release/5.8.0 immer existiert
  (`includes/src/Shop.php`), die Absicherung ist also günstige Vorsorge für
  die deklarierte `MinShopVersion`-Kompatibilität mit 5.7.x (nicht
  unabhängig geprüft), kein echtes Risiko auf 5.8.

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
`Bootstrap.php` nötig), `JTLSmarty::assign()`/`fetch()`.
- `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` (genutzt von `BackupService`/
  `RestoreService`s `buildDsn()`, um mysqldump-phps eigene, separate
  DB-Verbindung zu öffnen) — BESTÄTIGT gegen
  `includes/src/Installation/VueInstaller.php`, das bei einer frischen
  Installation genau diese vier Konstantennamen in die
  `config.JTL-Shop.ini.php` schreibt.
- `$_SESSION['AdminAccount']->kAdminlogin` (ID des aktuell eingeloggten
  Admins, fürs Audit-Log) — BESTÄTIGT gegen
  `includes/src/Router/Controller/Backend/AdminAccountController.php`,
  das genau diese Eigenschaft liest/schreibt.
- `JTL\Plugin\Data\Config::getValue()` vs. `getDecryptedValue()` für
  verschlüsselte Einstellungen, und die `base64(XTEA(...))`-Speicherform
  dahinter — relevante Historie, auch wenn dieses Plugin `Config` inzwischen
  gar nicht mehr nutzt (siehe die Einstellungen-Speicher-Umstellung weiter
  unten), da `SettingsRepository` genau diese Form jetzt gegen seine eigene
  Tabelle repliziert.

Details stehen in den Docblocks im Code direkt bei der jeweiligen
Entscheidung.

## Gegen eine echte laufende Installation + den Shop-Core-Quellcode bestätigt

Nach der Installation auf einem echten 5.8.0-rc3-Shop tauchten mehrere
subtile Bugs auf, die sich alle mit einer eindeutigen, quellcode-bestätigten
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
- **Manager zeigte nach einer Neuinstallation keine Backups, obwohl die
  Dateien noch auf dem Server lagen.** Die Historie-Tabelle wird bei jeder
  Deinstallation/Neuinstallation leer neu angelegt; die Backup-Dateien selbst
  liegen bewusst außerhalb des Plugin-eigenen Ordners und überstehen das
  unangetastet. Neuer `StorageReconciliationService`, der bei jedem
  Dashboard-/Backups-Seitenaufruf automatisch läuft und für jede Backup-Datei
  auf der Festplatte (über ihre `.manifest.json`-Begleitdatei), die noch
  nicht erfasst ist, eine Historie-Zeile nachträgt — rein additiv, rührt nie
  eine bestehende Zeile an.
- **„Einstellungen" konnte über das native `<Settingslink>`-Formular weder
  immer sichtbare Hilfetexte noch ein bedingtes Feld zeigen** (z. B. das
  Verschlüsselungs-Passwort erst bei gesetztem Haken). Gegen
  `admin/templates/bootstrap/tpl_inc/plugin_options.tpl` und
  `help_description.tpl` bestätigt: `<Setting><Description>` wird immer nur
  als Hover-Tooltip gerendert, und `PluginController::renderMenu()` bietet
  Plugins keinen Hook, um eigenes JS/HTML in dieses automatisch generierte
  Formular einzuschleusen. Ersetzt durch einen komplett eigenen
  Customlink-Tab (`SettingsPageController`). Zwei Stufen: zuerst über
  denselben nativen Speicher-Endpunkt (`PluginController::actionConfig()`),
  bei laufendem „Erweiterte Einstellungen (Rohformular)"-Fallback-Tab, rein
  um dessen `<Setting>`-Schema registriert zu halten (`SettingsLinks::
  install()` legt für ein `<Settingslink>` immer einen sichtbaren
  Menüeintrag an, bestätigt keine „headless"/Schema-only-Option) — dann,
  sobald dieser Fallback-Tab selbst nicht mehr gewünscht war, die
  Einstellungen komplett in eine eigene Plugin-Tabelle verschoben
  (`Service/SettingsStore`, `Migration20260827140000`, die bereits
  gespeicherte native Werte einmalig mit übernimmt, damit ein Update nichts
  verliert) und das `<Settingslink>` ganz aus `info.xml` entfernt.
  Verschlüsselte Felder behalten exakt dieselbe Speicherform —
  `base64(XTEA(Klartext))` über den shop-eigenen `CryptoServiceInterface`
  — sodass keine neue Schlüsselverwaltung nötig wurde.
- **Der wiederkehrende Cronjob hat bei jedem Lauf still nichts getan** —
  unabhängig vom (und zusätzlich zum) Registrierungs-Bug unten:
  `Cron/BackupCronJob.php` rief `Helper::getLoaderByPluginID(self::PLUGIN_ID)`
  mit der STRING-ID des Plugins auf, aber die echte Signatur dieser Methode
  ist `getLoaderByPluginID(int $id, ...)` — die NUMERISCHE `kPlugin`. Wegen
  `declare(strict_types=1)` in dieser Datei wirft das Übergeben eines Strings
  dort sofort einen `TypeError`, der vom eigenen pauschalen
  `catch (\Throwable)` des Jobs still verschluckt wird (der genau dafür da
  ist, dass ein Plugin-Fehler nie den kompletten Cron-Lauf des Shops zum
  Absturz bringt) — selbst mit korrekt registriertem Job-Typ hätte also jeder
  geplante Lauf sofort geworfen und nichts getan, nicht unterscheidbar von
  „gelaufen, nichts zu sichern konfiguriert". BESTÄTIGT gegen
  `includes/src/Plugin/Helper.php`: `getPluginById(string $pluginID):
  ?PluginInterface` ist die richtige Methode für genau diesen Fall — nimmt
  die String-ID entgegen, löst die numerische selbst über eine gecachte
  Abfrage auf und gibt ein bereits geladenes Plugin direkt zurück. Behoben
  in `Cron/BackupCronJob.php` und im neuen `Cron/FullBackupCronJob.php`.
- **Der Cronjob-Typ hat sich nie tatsächlich registriert**, sichtbar als
  `Undefined array key "jobType"` in `Bootstrap.php` auf der Cron-Verwaltung
  des Shops. `Bootstrap::boot()`s beide `Dispatcher`-Listener gingen von
  einer angenommenen, nie verifizierten Event-Args-Struktur aus. Gegen
  `CronController::getAvailableCronJobs()` und `Mapper/JobTypeToJob::map()`
  bestätigt: `GET_AVAILABLE_CRONJOBS` feuert mit `['jobs' => &$available]`
  (eine flache Liste von Typ-Strings), und `MAP_CRONJOB_TYPE` feuert mit
  `['type' => $type, 'mapping' => &$mapping]` — nicht die zuvor
  angenommenen Schlüssel `jobTypes`/`jobType`/`jobClass`. `Dispatcher::fire()`
  ist `: void` und verwirft den Rückgabewert eines Listeners komplett, daher
  erreicht nur das Verändern dieser *referenzierten* Array-Elemente die
  aufrufende Stelle. Ein Nebeneffekt zum Wissen: das „Typ"-Dropdown unter
  Cron → Anlegen rendert über das core-eigene `{__($type)}`, das keine
  Übersetzung für einen vom Plugin registrierten Typ-String kennt — es zeigt
  daher den rohen Bezeichner `plugin:jtl_dbbackup_tool_cron` unverändert an,
  nicht per Plugin behebbar, stattdessen in der Dashboard-Anleitung
  dokumentiert.
- **Die Erfolgsmeldung eines Backup-Laufs konnte auf dem falschen Tab
  erscheinen** (z. B. Klick auf „Backup jetzt" im Tab „Erstellen", Meldung
  aber im Dashboard sichtbar). Jede Adminmenu-Customlink-Datei wird bei
  jedem Request ausgeführt, um alle Tabs vorzurendern — dasselbe
  `preset`-POST ist daher sowohl für `BackupController` als auch
  `DashboardController` sichtbar, und wer zuerst `RequestGuard::
  claimBackupTrigger()` gewinnt (eine Frage der Ausführungsreihenfolge, nicht
  des tatsächlich betrachteten Tabs), behielt das Ergebnis bisher nur lokal.
  Behoben mit `Service/FlashBus`, einem request-weiten Relay, aus dem jeder
  Controller liest, wenn er die Aktion nicht selbst verarbeitet hat, gerendert
  identisch über ein neues gemeinsames `_partials/flash.tpl` oben auf jedem Tab.
- **Ein echter fataler Smarty-Compile-Fehler** („unknown function 'function'")
  auf dem Backups-Tab, ausgelöst durch ein dichtes Inline-`onclick` mit
  rohem JavaScript — Smartys eigene Template-Trennzeichen sind ebenfalls
  „{"/"}", sodass ein „{" direkt vor einem Wort wie `function` als
  fehlerhaftes Smarty-Tag geparst wurde. Das Inline-Handler wurde entfernt;
  jeder verbleibende JS/CSS-Block in den Templates dieses Plugins ist jetzt
  vorsorglich in `{literal}...{/literal}` eingepackt, nicht nur der eine,
  der tatsächlich live gebrochen ist.

## Nächste Schritte

1. Die beiden Cron-Fixes oben auf der echten Installation Ende-zu-Ende
   bestätigen: der Job-Typ erscheint jetzt im „Typ"-Dropdown von Cron →
   Anlegen, und ein echter geplanter Lauf erzeugt auch wirklich ein Backup
   (nicht nur „kein Fehler") — gerade der `TypeError`-Bug könnte plausibel
   eine ganze Weile still fehlgeschlagen sein, bevor er hier auffiel.
2. Die restliche „Bewusste Scope-Entscheidungen"-Liste durchgehen — die
   meisten sind so in Ordnung, aber der Async-Cron-Queue-Punkt lohnt sich zu
   überdenken, falls „Komplett"-Backups spürbar langsam werden; siehe diesen
   Punkt für das, was dazu tatsächlich geprüft wurde und warum es kein
   einfacher Umbau ist.
3. Echtes Backup/Restore-Roundtrip-Testing, wie es der Spec als
   QA-Anforderung vorsieht (weiterhin der einzige Punkt ganz ohne
   automatisierte Abdeckung — siehe „Gar nicht umgesetzt").
