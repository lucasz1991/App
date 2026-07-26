# RailTime als installierbare Web-App mit Web Push

RailTime verwendet eine Progressive Web App (PWA), keinen separaten App-Store-
Wrapper. Das Manifest macht die Anwendung auf iPhone, iPad und Android
installierbar. Ein Service Worker verarbeitet Push-Nachrichten fuer interne
Nachrichten und Chats.

## Voraussetzungen

- PHP 8.2 oder neuer und Laravel 12
- HTTPS im Produktivbetrieb
- Die Domain muss direkt auf `App/public` zeigen. Ein Betrieb in einem
  Unterordner ist fuer statische PWA-Dateien vorbereitet, aber Livewire erwartet
  in der vorhandenen Installation weiterhin einen Origin-Root.
- Ein stabiler `APP_KEY`: Er darf nach der ersten Registrierung nicht
  ausgetauscht werden, weil Push-Endpunkte und Schluessel verschluesselt
  gespeichert werden.
- Eine laufende Datenbank-Queue fuer zeitnahe Zustellung

## Einrichtung

1. Abhaengigkeiten und Assets installieren:

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```

2. VAPID-Schluessel einmalig erzeugen:

   ```bash
   php artisan webpush:vapid
   ```

   Die erzeugten Werte sicher in der Produktivumgebung hinterlegen. Sie duerfen
   spaeter nicht regelmaessig rotiert werden, weil Browserabonnements an den
   oeffentlichen Schluessel gebunden sind.

3. Umgebung konfigurieren:

   ```dotenv
   WEBPUSH_ENABLED=true
   WEBPUSH_TEST_ENABLED=false
   WEBPUSH_AUTO_PROVISION=true
   WEBPUSH_QUEUE=default
   WEBPUSH_DEFAULT_TTL=3600
   WEBPUSH_ALLOWED_ENDPOINT_HOSTS="fcm.googleapis.com,*.push.services.mozilla.com,*.push.apple.com,*.notify.windows.com,*.wns.windows.com,*.push.samsung.com"
   VAPID_SUBJECT="mailto:technik@example.com"
   VAPID_PUBLIC_KEY=
   VAPID_PRIVATE_KEY=
   ```

   `VAPID_SUBJECT` muss eine `mailto:`-Adresse oder eine HTTPS-URL sein. Weitere
   Endpoint-Hosts nur nach einer bewussten Sicherheitspruefung ergaenzen.

   Wenn `WEBPUSH_AUTO_PROVISION=true` gesetzt ist und beide VAPID-Schluessel
   fehlen, erzeugt die erste serverseitige Push-Diagnose einmalig ein
   Schluesselpaar. Es wird nicht in Git oder im Webroot, sondern unter
   `storage/app/private/webpush-vapid.json` gespeichert. Eine gueltige
   HTTPS-`APP_URL` wird bei fehlendem `VAPID_SUBJECT` als Kontakt-URL verwendet.
   Vorhandene, unvollstaendige oder ungueltige Schluessel werden nicht
   automatisch ersetzt, damit bestehende Abonnements nicht unbemerkt ihre
   Bindung verlieren.

4. Datenbank aktualisieren und Caches erneuern:

   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. Einen dauerhaften Worker starten. Der Minutentakt im Scheduler bleibt nur
   als Rueckfalloption bestehen:

   ```bash
   php artisan queue:work database --queue=default --tries=4 --backoff=30 --timeout=90
   ```

   Der Prozess muss durch Supervisor, systemd oder die Prozessverwaltung des
   Hosters automatisch neu gestartet werden. Nach jedem Deployment:

   ```bash
   php artisan queue:restart
   ```

6. Fuer Echtzeit-Hinweise im geoeffneten Browser Laravel Reverb wie bisher
   betreiben. Web Push und Reverb ergaenzen sich: Reverb aktualisiert die offene
   App, Web Push erreicht Hintergrund und geschlossene App.

## Aktivierung durch Benutzer

- Android: Im Profil den Bereich `App & Push` oeffnen. Die App kann ueber den
  angebotenen Button installiert werden; Push kann in unterstuetzten Browsern
  auch ohne Installation aktiviert werden.
- iPhone/iPad: RailTime zuerst ueber das Teilen-Menue zum Home-Bildschirm
  hinzufuegen und von dort starten. Erst in dieser Web-App kann der Benutzer
  Push nach einem direkten Klick erlauben.
- Bei verweigerter Browserfreigabe fragt RailTime nicht wiederholt nach. Die
  Berechtigung muss dann in den System- beziehungsweise Website-Einstellungen
  geaendert werden.
- Die Freigabe ist lokal an das RailTime-Konto gebunden. Bei einem Kontowechsel
  wird ein fremdes Browser-Abonnement nicht uebernommen; das neue Konto muss
  Push bewusst erneut aktivieren. Ein Testversand geht nur an das gerade
  aktivierte Geraet.

### Lokaler Test und Smartphone-Test

`localhost` und `127.0.0.1` gelten im Browser auf demselben Entwicklungsrechner
als sicherer Kontext. Ein Smartphone kann die Loopback-Adresse des Rechners
jedoch nicht verwenden. Fuer iPhone, iPad oder Android ist deshalb eine vom
Geraet erreichbare HTTPS-Adresse erforderlich; eine unverschluesselte
LAN-Adresse reicht fuer Service Worker und Web Push nicht aus.

Vor dem Test muessen drei Bedingungen gleichzeitig erfuellt sein:

1. Das Geraet wird unter `Profil -> App & Push` bewusst aktiviert und erscheint
   danach als aktives Geraet.
2. Ein dauerhafter Queue-Worker verarbeitet `default`. Auf Plesk uebernimmt
   dies der im Laravel Toolkit aktivierte Queue-Worker; RailTime startet keinen
   zweiten Worker ueber den Scheduler.
3. Erst dann wird der Testversand fuer genau dieses Geraet ausgeloest.

Push-Vorschauen enthalten absichtlich weder Betreff noch Nachrichteninhalt.
Die Detailansicht wird erst nach Anmeldung und serverseitiger
Berechtigungspruefung geoeffnet.

## Rollout

1. Zuerst auf Staging mit `WEBPUSH_TEST_ENABLED=true` und einem Testkonto
   pruefen.
2. Danach `WEBPUSH_ENABLED=true` fuer eine kleine Pilotgruppe beziehungsweise
   die Zielumgebung aktivieren. Der Bereich `Profil -> App & Push` bleibt
   absichtlich immer sichtbar und nennt bei fehlender Serverkonfiguration die
   konkrete Voraussetzung.
3. Den Testversand im Produktivbetrieb wieder mit
   `WEBPUSH_TEST_ENABLED=false` abschalten.
4. Bei Problemen kann die Zustellung sofort mit `WEBPUSH_ENABLED=false`
   deaktiviert werden. Registrierungen bleiben erhalten und die bestehende
   Reverb-/Inbox-Funktion arbeitet weiter.

## Abnahmematrix auf echten Geraeten

- iPhone/iPad: Installation, App-Icon, Standalone-Start, Erlauben/Ablehnen,
  Nachricht bei geoeffneter, im Hintergrund liegender und geschlossener App,
  Klick auf Nachricht und Chat.
- Android: Installation, Erlauben/Ablehnen, dieselben drei App-Zustaende und
  beide Deep Links.
- Alle Rollen: Administrator, Verwaltung, Mitarbeiter und Gast; Profilbereich,
  Dashboard, Burger-Menue, Logo und Karten bei 320, 360, 390 und 430 Pixeln.
- Datenschutz: Sperrbildschirm zeigt nur den generischen Hinweis.
- Betrieb: `failed_jobs` und das Anwendungslog kontrollieren, ohne Push-Endpunkte
  oder Schluessel zu protokollieren.

## Fehlersuche

- `Push-Server: Nicht bereit`: Die Hilfe-Seite nennt jetzt die konkret fehlende
  Voraussetzung, ohne Schluesselwerte auszugeben. Hauefig sind
  `WEBPUSH_ENABLED`, ein VAPID-Schluessel oder ein gueltiges `VAPID_SUBJECT`
  noch nicht in der aktiven Serverkonfiguration angekommen. Nach Aenderungen
  `php artisan optimize:clear && php artisan config:cache` ausfuehren.
- Keine zeitnahe Zustellung: dauerhaften Queue-Worker und dessen Queue-Liste
  `default` pruefen.
- iPhone bietet keine Freigabe: App vom Home-Bildschirm starten und HTTPS sowie
  iOS/iPadOS-Version pruefen.
- Service Worker bleibt alt: HTTPS-Header und `Cache-Control: no-cache,
  no-store, must-revalidate` fuer `service-worker.js` pruefen.
- Installation fehlt: Manifest und Icons ueber die tatsaechliche
  Produktivdomain laden; die Domain muss auf `public` zeigen.
- `/icons/pwa-192.png` liefert 404: Das weist meist auf einen unvollstaendigen
  Transfer von `public/icons` oder auf eine Plesk-Regel fuer physische
  Unterordner hin. Manifest, Service Worker, HTML-Head und Push-Payload nutzen
  deshalb den kanonischen Laravel-Pfad `/pwa-icons/{datei}`; der Controller
  liefert die echte Datei oder erzeugt ein gueltiges PNG. Der alte `/icons`-
  Pfad bleibt fuer bereits installierte Clients erhalten. Wenn Plesk diesen
  Legacy-Pfad vor Laravel abfaengt, muss auch `public/icons/.htaccess`
  ausgerollt sein und `AllowOverride` Rewrites zulassen.
