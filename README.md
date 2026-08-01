# RailTime

Interne Einsatz- und Kommunikationsplattform für den Bahnlogistik-Betrieb.
RailTime bildet die Kette `Auftrag → Schicht → Mitarbeiter → Durchführung →
Nachweis` in einem System ab — für die Verwaltung am Rechner und für die
Mitarbeitenden vor Ort als installierbare App auf dem Telefon.

**Vollständige Beschreibung und Fahrplan:**
[`.lmzdev/projektuebersicht/PROJEKTUEBERSICHT.md`](.lmzdev/projektuebersicht/PROJEKTUEBERSICHT.md)

---

## Kurzfassung

RailTime besteht aus sechs aufeinander aufbauenden Bausteinen. Die unteren sind
Voraussetzung für die oberen: Eine automatisierte Disposition kann nur so gut
sein wie die Auftrags- und Schichtdaten darunter.

| # | Baustein | Inhalt | Stand |
|---|---|---|---|
| 1 | **Plattform & Kommunikation** | Konten, Rollen und Team-Rechte, Nachrichten, Chat, Dateien, Push, Firmendaten | umgesetzt |
| 1b | **Video- und Sprachanrufe** | Anrufe aus dem Chat, Gruppenräume, Moderation, Bildschirmfreigabe | umgesetzt |
| 2 | **Auftragsverwaltung** | Aufträge, Kunden, Einsatzorte, Dokumente, Nachweise vor Ort | in Umsetzung |
| 3 | **Schichtplanung & Kalender** | Dienstpläne, Qualifikationen, Verfügbarkeiten, Ruhezeiten, Abwesenheiten, Schichttausch | in Umsetzung |
| 4 | **Assistierte Disposition** | Begründete Vorschlagsliste statt manueller Suche | geplant |
| 5 | **Automatisierte Schichtleitung** | Gestaffelte Ansprache, Zusage per Tipp, Eskalation an die Verwaltung | Zielbild |
| 6 | **Lernende Optimierung** | Vorschläge und Zeitplanung werden mit jeder Disposition besser | Zielbild |

Der Kern des Zielbilds: Ein Auftrag, der um 21:40 Uhr hereinkommt, ist heute
faktisch nicht annehmbar, weil niemand mehr telefoniert. Mit automatisierter
Ansprache — nach Qualifikation, Ruhezeit, Entfernung und bisheriger Belastung —
ist er innerhalb von Minuten besetzt oder klar abgelehnt. Jede Disposition ist
dabei eine Rückmeldung, aus der die nächste besser wird. Die Entscheidung bleibt
beim Menschen; das System übernimmt das Suchen, Nachtelefonieren und Nachhalten.

### Aufträge und Schichtplanung

Der erste produktive Vertikalschnitt bildet `Kunde → Auftrag → Schicht →
Mitarbeiterzuweisung` in der Datenbank ab. Administratoren pflegen die vier
zusammenhängenden Arbeitsbereiche unter **Kunden**, **Aufträge**,
**Schichtplanung** und **Kalender**. Auftragsstatus werden nachvollziehbar
protokolliert; die manuelle Schichtzuweisung verhindert bereits zeitliche
Überschneidungen derselben Person. Der Kalender ist zunächst eine interne
Wochenansicht auf diese Schichtdaten.

Google Calendar, Apple Kalender beziehungsweise ICS-Abonnements sind noch kein
Teil dieses Schritts. Die externe Synchronisation wird später auf dem internen
Schichtmodell aufgebaut, damit RailTime die fachliche Quelle der Wahrheit
bleibt.

Planbare Zeitpunkte werden als UTC in `DATETIME`-Spalten gespeichert; die
jeweilige IANA-Zeitzone bleibt am Auftrag und an der Schicht erhalten. Eingaben
und Ausgaben werden dadurch lokal dargestellt, ohne den eindeutigen Zeitpunkt
für spätere Kalenderadapter zu verlieren. Änderungen an Auftrags- und
Schichtzeiträumen laufen über zentrale Services: Ein Auftrag darf bestehende
aktive Schichten nicht ausschließen und eine bereits belegte Schicht darf keine
zeitliche Doppelbelegung erzeugen.

Nach dem Aktualisieren des Codes muss das neue Fachschema angelegt werden:

```bash
php artisan migrate
```

Die derzeit für Teilstringsuche benötigten geschäftlichen Kundenkontakte werden
noch unverschlüsselt gespeichert. Vor einem produktiven Rollout ist die
Entscheidung zwischen verschlüsseltem Suchindex beziehungsweise Blindindex und
einer dokumentierten betrieblichen Ausnahme samt Löschkonzept verbindlich zu
treffen.

**Technisch:** Laravel 12, Livewire 3, Alpine, Tailwind, MySQL. Echtzeit über
Laravel Reverb, Videotelefonie über LiveKit, Push über Web Push und Service
Worker. Betrieb auf eigener Infrastruktur unter Plesk — die Daten bleiben im
Haus.

---

## Installation

RailTime braucht neben der Anwendung selbst **vier Dienste**, die getrennt
eingerichtet werden müssen. Ohne sie startet die App zwar, aber Echtzeit,
Benachrichtigungen, Hintergrundaufgaben und Anrufe funktionieren nicht.

| Dienst | Wofür | Ohne ihn |
|---|---|---|
| **Queue-Worker** | Push-Versand, Ring-Timeouts, Mailversand | Benachrichtigungen bleiben liegen; Anrufe klingeln endlos |
| **Reverb (WebSockets)** | Echtzeit in der geöffneten App | Chat und Klingeln aktualisieren erst nach Neuladen |
| **Scheduler (Cron)** | Stündliches Aufräumen abgelaufener Dateien | Abgelaufene Dateien bleiben liegen |
| **LiveKit Media-Server** | Bild- und Tonströme der Anrufe | Anrufe kommen nicht zustande |

### 1. Voraussetzungen

- PHP 8.2 oder neuer, Composer
- Node.js mit npm
- MySQL
- Im Produktivbetrieb: **HTTPS**, und die Domain muss direkt auf `App/public`
  zeigen. Ein Betrieb in einem Unterordner wird von Livewire nicht unterstützt.

### 2. Anwendung einrichten

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

> **Wichtig:** Der `APP_KEY` darf nach dem ersten Produktivstart **nicht** mehr
> getauscht werden. Nachrichteninhalte, Profildaten und Push-Endpunkte sind
> damit verschlüsselt; ein Wechsel macht sie unlesbar.

Datenbankzugang in der `.env` eintragen, dann:

```bash
php artisan migrate --seed
npm run build
```

### 3. Queue-Worker

RailTime verwendet die Datenbank-Queue.

```dotenv
QUEUE_CONNECTION=database
WEBPUSH_QUEUE=default
```

Es wird **ein** dauerhafter Worker auf der Queue `default` benötigt:

```bash
php artisan queue:work database --queue=default --tries=4 --backoff=30 --timeout=90
```

Der Prozess muss automatisch neu starten. **Auf Plesk** übernimmt das der im
Laravel-Toolkit aktivierte Queue-Worker — RailTime startet bewusst keinen
zweiten Worker über den Scheduler und richtet auch keinen über Supervisor ein.
Zwei Worker auf derselben Queue führen zu doppelt verarbeiteten Jobs.

Nach jedem Deployment:

```bash
php artisan queue:restart
```

### 4. Reverb (Echtzeit)

Zugangsdaten erzeugen — vorhandene vollständige Daten bleiben unverändert:

```bash
php artisan railtime:reverb-keys
```

Einmalig mit Root-Rechten im Projektverzeichnis den Dienst einrichten:

```bash
sudo "$(php -r 'echo PHP_BINARY;')" artisan railtime:install-reverb-service
```

Der Installer erkennt Projektpfad, Plesk-Systembenutzer und PHP-Version,
installiert Supervisor falls nötig, schreibt eine idempotente
`railtime-reverb`-Konfiguration, startet Reverb als Projektbenutzer (nicht als
`root`), baut die Assets mit dem neuen öffentlichen Schlüssel neu und prüft
abschließend Prozessstatus und Port 8080.

Vorher prüfen, was passieren würde:

```bash
php artisan railtime:install-reverb-service --dry-run
```

**Einmalige Handarbeit am Webserver:** Der WebSocket-Proxy von der öffentlichen
HTTPS-Domain auf `127.0.0.1:8080` muss in Plesk unter *Apache & nginx-
Einstellungen → zusätzliche nginx-Direktiven* eingerichtet werden. Der
Speicherort hängt von Domain und Plesk-Version ab und lässt sich deshalb nicht
automatisieren.

Schlüssel bewusst rotieren (danach müssen alle Clients neu laden):

```bash
php artisan railtime:reverb-keys --force
npm run build
php artisan reverb:restart
```

### 5. Web Push und App-Installation

VAPID-Schlüssel einmalig erzeugen:

```bash
php artisan webpush:vapid
```

```dotenv
WEBPUSH_ENABLED=true
WEBPUSH_TEST_ENABLED=false
WEBPUSH_QUEUE=default
VAPID_SUBJECT="mailto:technik@example.com"
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

> Die VAPID-Schlüssel dürfen **nicht** regelmäßig rotiert werden —
> Browserabonnements sind an den öffentlichen Schlüssel gebunden und würden
> ungültig.

Push erfordert HTTPS. Auf iPhone und iPad muss die App zuerst über das
Teilen-Menü zum Home-Bildschirm hinzugefügt und von dort gestartet werden; erst
in dieser Web-App kann Push erlaubt werden.

Die Implementierung ist abgeschlossen. Für neue Installationen bleiben die
einmalige VAPID-Konfiguration, HTTPS und die Berechtigungsfreigabe auf dem
jeweiligen Endgerät erforderlich.

### 6. Scheduler

Ein Cron-Eintrag ruft den Laravel-Scheduler jede Minute auf. Er räumt stündlich
abgelaufene Dateien ab.

```cron
* * * * * cd /pfad/zu/App && php artisan schedule:run >> /dev/null 2>&1
```

Auf Plesk lässt sich das über *Geplante Aufgaben* einrichten.

### 7. Media-Server für Anrufe

Video- und Sprachanrufe verwenden LiveKit. Der Media-Server ist für RailTime
eingerichtet und die Anwendung ist angebunden. Bei einer neuen Installation
müssen LiveKit, die öffentliche WSS-Domain, TLS und die erforderlichen
RTC-/TURN-Ports separat bereitgestellt werden.

Schlüssel auf dem Anwendungsserver erzeugen:

```bash
php artisan railtime:livekit-keys --host=livekit.rail-time.de
```

Anbindung prüfen:

```bash
php artisan config:clear
php artisan railtime:livekit-check
```

### 8. RailTime Assist und gemeinsamer Sprachdienst

Der seitenweite Livewire-Assistent steht aktiven, verifizierten Benutzern mit
dem Teamrecht **Chatbot-Assistent verwenden** zur Verfügung. Zusätzlich lässt
er sich unter **Administration → Einstellungen → System → Chatbot-Assistent**
global deaktivieren. Auf der Chatseite sowie in den Admin- und persönlichen
Profileinstellungen wird er bewusst nicht eingeblendet. Er beantwortet
Bedien- und Orientierungsfragen über das in **Administration → Einstellungen →
OpenRouter** gepflegte Textmodell.
Der API-Key wird verschlüsselt gespeichert und ausschließlich serverseitig
verwendet. Der Assistent besitzt keinen zusätzlichen Live-Zugriff auf
personenbezogene oder aktuelle Betriebsdaten und führt selbst keine Änderungen
aus. Er verarbeitet den sicheren Seitenkontext, Inhalte, die der Benutzer
ausdrücklich eingibt oder anhängt, sowie freigegebene Informationen aus dem
redaktionellen Wissenspool.

Der Wissenspool liegt ausschließlich im Superadmin-Tab unter **Administration
→ Einstellungen → Superadmin → Informationspool des Chatbot-Assistenten**.
Dort werden der **Default-Prompt** für Rolle und Ton, **Wichtige Regeln**, ein
kompakter Basistext, frei anlegbare Themen und einzelne Wissenseinträge gepflegt.
Default-Prompt und Regeln sind vertrauenswürdige Superadmin-Vorgaben und gelten
bei jeder Antwort; fest eingebaute RailTime-Sicherheitsregeln können sie nicht
aufheben. Die Werte liegen unter `assistant/default_prompt` und
`assistant/binding_rules`; fehlende Werte verwenden sichere Programmstandards.

Nur der Basistext, die Themenübersicht und ausdrücklich als Basisinfo markierte
Kurzfassungen begleiten jede Anfrage als Referenzdaten. Volltexte bleiben in
RailTime, bis das Textmodell bei einer passenden Frage das serverseitig
validierte Tool `search_assistant_knowledge` anfordert. RailTime führt die Suche
lokal aus, begrenzt Treffer und Textmenge und sendet erst danach die passenden
Auszüge an OpenRouter. Inaktive Themen oder Einträge werden niemals geliefert;
der Chatbot erhält keinen allgemeinen Datenbankzugriff. Auch in diesem Pool
dürfen keine Zugangsdaten, personenbezogenen Daten oder Betriebsgeheimnisse
abgelegt werden.

Der Einstieg ist ein kleines rotes, textfreies 3D-Virtual-Pet: ein weicher,
gedrungener Kapselkörper mit eingelassenem Gesichtsdisplay, zwei organischen
Blattfühlern und winzigen Füßen statt eines gewöhnlichen Floating Buttons. Das
eigenständige Modell öffnet per Klick denselben Livewire-Chat, zeigt kurze
Sprechblasen und besitzt eine ruhige Idle-Animation aus Schweben, Atmen,
Blinzeln und dezentem Blattwippen; offline wird es entsättigt dargestellt. Das
Panel erscheint am Desktop unten rechts und mobil als
Bottom-Sheet. Im Einstellungsmenü des Chats liegen automatisches Vorlesen,
Vorlesetempo, automatisches Hören mit bestätigbarem Transkript und lokale,
kostenfreie Seitenhinweise aus dem RailTime-Hilfekatalog.

Der Composer akzeptiert bis zu drei Text-, PDF-, Bild- oder moderne
Office-Dateien (`docx`, `xlsx`, `pptx`). Text und Office-Inhalte werden
serverseitig rein lesend extrahiert; Bilder werden vor der Analyse neu kodiert
und von Metadaten befreit. PDF- und Bildoriginale werden nur für die aktuelle
OpenRouter-Anfrage übertragen und danach zusammen mit allen übrigen temporären
Uploads gelöscht. In der verschlüsselten Sitzung verbleiben lediglich
begrenzte Metadaten und abgeleiteter Kontext bis zum Leeren des Chats oder zum
Sitzungsablauf.

Eingaben und der begrenzte Gesprächskontext werden für die Antwort an
OpenRouter übertragen. Vor der produktiven Aktivierung müssen daher die
betriebliche Datenschutz-/Aufbewahrungsfreigabe und der zulässige
Nutzungsrahmen festgelegt sein. Die Oberfläche weist ausdrücklich darauf hin,
keine personenbezogenen Daten, Betriebsgeheimnisse oder Zugangsdaten
einzugeben.

TTS und STT laufen bevorzugt nicht direkt im Browser oder in einem der beiden
Laravel-Prozesse. RailTime und Followflow verwenden den gemeinsamen,
loopback-gebundenen Dienst unter [`services/speech-service`](services/speech-service/README.md).
Jede App besitzt eine eigene Client-ID und ein eigenes Token. Der Dienst darf
weder per nginx/Apache veröffentlicht noch an eine öffentliche Adresse gebunden
werden. Er läuft unter dem eigenen unprivilegierten Benutzer `lmz-speech`, der
keine Schreibrechte in RailTime oder Followflow besitzt. RailTime kann der
Superadmin zusätzlich auf `Nur lokal`, `Nur extern` oder standardmäßig
`Lokal, bei Ausfall extern` stellen. Beim automatischen Fallback werden nur die
aktuelle Mikrofonaufnahme beziehungsweise der aktuelle Vorlesetext über die
konfigurierten OpenRouter-STT-/TTS-Modelle verarbeitet.

RailTime-Konfiguration nach erfolgreichem Dienst-Rollout:

```dotenv
SPEECH_SERVICE_ENABLED=true
SPEECH_SERVICE_URL=http://127.0.0.1:8092
SPEECH_SERVICE_CLIENT_ID=railtime
SPEECH_SERVICE_TOKEN_FILE=/var/www/vhosts/<railtime-webspace>/.lmz-secrets/speech-service.token
```

Die Token-Datei liegt außerhalb des Document Roots, aber innerhalb des eigenen
Plesk-`WEBSPACEROOT`, damit der PHP-FPM-Pool sie trotz des standardmäßigen
`open_basedir` lesen kann. Der zentrale
[`services/speech-service`-Rollout](services/speech-service/README.md#3-getrennte-plesk-tokens-innerhalb-von-open_basedir)
erzeugt sie als RailTime-Plesk-Benutzer mit Modus `600` und prüft zugleich,
dass der getrennte Followflow-Benutzer sie nicht lesen kann.

Status und optional die TTS-Pipeline prüfen, ohne Audio oder Geheimnisse auf
die Platte zu schreiben:

```bash
php artisan config:clear
php artisan railtime:speech-service-status
php artisan railtime:speech-service-status --smoke
```

Die bisherigen Followflow-CLI-Provider bleiben bis zur gemeinsamen
Produktionsabnahme nur als expliziter Rollback erhalten. RailTimes externer
Fallback ist davon getrennt, wird zentral protokolliert und kann nur vom
Superadmin geändert werden.

### 9. Deployment

Beim Assistant-Rollout muss die Datenmigration vor dem atomaren Wechsel auf
den neuen Release-Stand laufen. Danach werden Konfigurations-, View- und
Anwendungscache auf dem aktiven Release erneuert. So ist `assistant.use` bereits
in bestehenden Team-JSON-Werten vorhanden, bevor die neue Zugriffsschicht
Anfragen bewertet.

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan reverb:restart
```

---

## Entwicklung

```bash
php artisan serve
npm run dev
php artisan reverb:start
php artisan queue:work
```

Tests:

```bash
php artisan test
```

Die Testsuite läuft auf SQLite im Arbeitsspeicher und fasst die
Entwicklungsdatenbank nicht an. Der aktuelle Prüfstand wird in
[`.lmzdev/STATE.md`](.lmzdev/STATE.md) festgehalten.

---

## Dokumentation

Der lokale, bewusst gitignorierte Ordner `.lmzdev/` enthält ausschließlich den
aktuellen Projektstand, offene Aufgaben, Entscheidungen und Agent-Handoffs.
Abgeschlossene Themendossiers werden dort nicht dauerhaft aufbewahrt.

| Dokument | Inhalt |
|---|---|
| [`projektuebersicht/PROJEKTUEBERSICHT.md`](.lmzdev/projektuebersicht/PROJEKTUEBERSICHT.md) | Vollständige Projektbeschreibung und Fahrplan |
| [`TASKS.md`](.lmzdev/TASKS.md) | Belegte offene Themen, Priorität und nächster Schritt |
| [`STATE.md`](.lmzdev/STATE.md) | Bestätigter technischer Stand und Verifikation |
| [`DECISIONS.md`](.lmzdev/DECISIONS.md) | Dauerhafte Projektentscheidungen |
| [`COMMUNICATION.md`](.lmzdev/COMMUNICATION.md) | Append-only-Handoffs zwischen Coding-Agents |

---

*Entwicklung: Lucas M. Zacharias*
