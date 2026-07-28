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
| 1b | **Video- und Sprachanrufe** | Anrufe aus dem Chat, Gruppenräume, Moderation, Bildschirmfreigabe | Anwendung fertig, Media-Server ausstehend |
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

Details, Rollout und Fehlersuche:
[`.lmzdev/web-push-und-pwa/WEB_PUSH_PWA.md`](.lmzdev/web-push-und-pwa/WEB_PUSH_PWA.md)

### 6. Scheduler

Ein Cron-Eintrag ruft den Laravel-Scheduler jede Minute auf. Er räumt stündlich
abgelaufene Dateien ab.

```cron
* * * * * cd /pfad/zu/App && php artisan schedule:run >> /dev/null 2>&1
```

Auf Plesk lässt sich das über *Geplante Aufgaben* einrichten.

### 7. Media-Server für Anrufe

Video- und Sprachanrufe benötigen einen LiveKit-Server auf einer eigenen kleinen
VM mit öffentlicher IP, eigenen DNS-Einträgen und Firewall-Freigaben. Die
vollständige, geprüfte Anleitung steht in
[`.lmzdev/media-server-livekit-integration/UMSETZUNGSPLAN.md`](.lmzdev/media-server-livekit-integration/UMSETZUNGSPLAN.md),
Abschnitt 5.

Schlüssel auf dem Anwendungsserver erzeugen:

```bash
php artisan railtime:livekit-keys --host=livekit.rail-time.de
```

Anbindung prüfen:

```bash
php artisan config:clear
php artisan railtime:livekit-check
```

Zum Testen **ohne** eigene VM lässt sich LiveKit lokal im Docker betreiben —
siehe Abschnitt 8 desselben Dokuments.

### 8. Deployment

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart
php artisan reverb:restart
```

> `php artisan view:cache` schlägt derzeit an `resources/views/teams/show.blade.php`
> fehl und ist deshalb oben nicht enthalten. Siehe
> [`.lmzdev/befunde-und-empfehlungen/BEFUNDE.md`](.lmzdev/befunde-und-empfehlungen/BEFUNDE.md),
> Punkt C2.

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
Entwicklungsdatenbank nicht an. Derzeit sind 13 von 280 Tests rot — alle
vorbestehend und ohne Bezug zu neuen Funktionen, aufgeschlüsselt in
[`BEFUNDE.md`](.lmzdev/befunde-und-empfehlungen/BEFUNDE.md), Punkt C1.

---

## Dokumentation

Alle Entwicklungs- und Konzeptionsunterlagen liegen unter `.lmzdev/` in
Themenverzeichnissen.

| Dokument | Inhalt |
|---|---|
| [`projektuebersicht/PROJEKTUEBERSICHT.md`](.lmzdev/projektuebersicht/PROJEKTUEBERSICHT.md) | Vollständige Projektbeschreibung und Fahrplan |
| [`media-server-livekit-integration/UMSETZUNGSPLAN.md`](.lmzdev/media-server-livekit-integration/UMSETZUNGSPLAN.md) | Videotelefonie: Architektur, Server-Einrichtung, lokale Entwicklung |
| [`web-push-und-pwa/WEB_PUSH_PWA.md`](.lmzdev/web-push-und-pwa/WEB_PUSH_PWA.md) | Push-Benachrichtigungen, App-Installation, Rollout |
| [`einstellungssystem/EINSTELLUNGSSYSTEM.md`](.lmzdev/einstellungssystem/EINSTELLUNGSSYSTEM.md) | Zentrales Einstellungssystem |
| [`befunde-und-empfehlungen/BEFUNDE.md`](.lmzdev/befunde-und-empfehlungen/BEFUNDE.md) | Offene Befunde, Fehler und Optimierungsvorschläge |
| [`.ai-sync.md`](.lmzdev/.ai-sync.md) | Übergabeprotokoll für Coding-Agents |

---

*Entwicklung: Lucas M. Zacharias*
