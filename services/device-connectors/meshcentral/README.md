# RailTime MeshCentral Connector

Separat deploybarer Node-22-Adapter zwischen dem RailTime Connector Contract
`1.0.0` und der offiziell mit MeshCentral ausgelieferten `meshctrl.js`. Die
Providerabhängigkeit ist in `package.json` und `package-lock.json` exakt auf
**MeshCentral 1.2.5** fixiert; es wird kein `latest` verwendet. Der
Connector-Code selbst verwendet ausschließlich die Node-Standardbibliothek.

## Ehrlicher Funktionsumfang

| RailTime-Funktion | Technischer Weg | Rückmeldung |
|---|---|---|
| Health | `ServerInfo --json` | synchron und nur bei parsebarem JSON gesund |
| Enrollment | kein Connector-Enrollment | `POST /v1/enrollments` antwortet fail-closed mit `409 enrollment_not_supported` |
| Neustart | nicht angeboten | wird als nicht freigegebener Befehl mit `422` abgelehnt |
| Diagnose | `DeviceInfo --json` | synchron, nur datensparsame Skalare an RailTime |
| Skript | HMAC-Download → SHA-256 → `Upload` → `RunCommand --reply` | nur mit korrelationsgebundenem Erfolgsmarker abgeschlossen |
| Bildschirm-/Terminalsupport | native MeshCentral-Oberfläche | nicht als frei interpolierter Connectorbefehl angeboten |

Freigegebene Skriptarten sind ausschließlich `.ps1`, `.bat`, `.cmd` und `.sh`.
Requestoptionen werden vollständig abgelehnt. Zielverzeichnisse und Wrapper
kommen nur aus der lokalen JSON-Konfiguration. Der Prozess startet Befehle mit
`spawn(..., {shell:false})`; weder IDs noch Optionen werden in eine lokale Shell
eingesetzt.

Direkt vor der Ausführung prüft der Wrapper den freigegebenen SHA-256 nochmals
auf dem Zielgerät. Windows verwendet `Get-FileHash`; Linux verwendet
`sha256sum`, macOS bei Bedarf `shasum -a 256`. Ein fehlendes Hash-Werkzeug oder
eine Abweichung beendet den Auftrag ohne Ausführung. Die entfernte Staging-Datei
wird im PowerShell-`finally` beziehungsweise über einen POSIX-`trap` gelöscht;
das lokale Downloadverzeichnis wird unabhängig vom Ergebnis im Connector
entfernt. Ein nativer `install`-Befehl wird nicht angeboten: Installationen sind
nur innerhalb eines separat freigegebenen, gehashten Skripts möglich.

MeshCentral ist Fernsupport und keine vollständige UEM-/MDM-Lösung. Lock, Wipe,
Accountbereitstellung, Microsoft-365-/Google-Anmeldung und Apple-/Android-MDM
werden hier absichtlich nicht behauptet.

Ein nativer MeshCentral-Gruppeninvite ist weder an genau eine RailTime-
Mitarbeiterzuordnung gebunden noch über diesen Adapter mit exakter TTL einzeln
widerrufbar. Deshalb meldet der Connector `enrollment=false` und erzeugt keine
Invite-Links. Ebenso wird kein Neustart angenommen: `meshctrl DevicePower`
belegt nur die Annahme, aber ohne Callback/Poller nicht den Abschluss.

## Sicherheitsgrenzen

- alle drei Endpunkte verlangen einen Connector-Bearer-Token;
- keine Kennwörter und keine Secrets in Umgebungsvariablen;
- RailTime-Artefakte nur von einer exakten HTTPS-Host-Allowlist, ohne Redirect,
  Query oder Fragment;
- DNS-Auflösung wird vor dem Download geprüft und für die TLS-Verbindung auf
  die geprüfte Adresse gepinnt;
- `X-RailTime-Signature` ist
  `sha256=hmac(secret, timestamp + ".GET." + pathname)`;
- Größe, optionaler `X-Content-SHA256` und tatsächlicher SHA-256 müssen zum
  RailTime-Auftrag passen;
- derselbe SHA-256 wird nach dem Upload unmittelbar auf dem Zielgerät geprüft;
  bei Abweichung fehlt der Erfolgsmarker und der Auftrag schlägt fail-closed
  als `artifact_target_integrity_failed` fehl;
- Bearer-, HMAC- und MeshCentral-Loginkey-Dateien müssen unter Linux
  `0600`/`0400`, State- und Tempverzeichnisse `0700` besitzen;
- Idempotenz wird vor jedem Seiteneffekt als at-most-once-Journal geschrieben.
  Ein nach einem Crash unklarer Auftrag wird nicht automatisch wiederholt;
  RailTime erhält stattdessen einen fachlich zu prüfenden `409`;
- Body-, CLI-Ausgabe- und Laufzeitlimits sind fest begrenzt;
- Logs enthalten keine Tokens, Signaturen, Download-URLs oder Kommandozeilen.

Wichtige Upstream-Einschränkung: Die offizielle `meshctrl.js` 1.2.5 setzt bei
ihrem WebSocket `rejectUnauthorized:false`. Der Connector erzwingt deshalb für
`meshcentral.url` eine **Loopback-WSS-Adresse auf demselben Plesk-Server**.
Die von Mitarbeitenden und Administratoren genutzte MeshCentral-Oberfläche
bleibt ein separat per Plesk/TLS abgesicherter HTTPS-Dienst. Eine öffentliche
`wss://support.example...`-Adresse als Connector-Upstream wird beim Start
abgelehnt.

## Konfigurationsdateien statt ENV

1. `config.example.json` nach
   `/etc/railtime-meshcentral-connector/config.json` kopieren.
2. Drei eigene Dateien anlegen:
   - `/run/railtime-meshcentral-secrets/railtime-bearer-token`
   - `/run/railtime-meshcentral-secrets/railtime-hmac-secret`
   - `/run/railtime-meshcentral-secrets/meshcentral-login-key`
3. Den Inhalt von Bearer-Token und HMAC-Secret mit mindestens 32 zufälligen
   Zeichen erzeugen. Dieselben Werte anschließend in RailTime unter
   **Einstellungen → Geräte-Setup → MeshCentral** eintragen.
4. Den bestehenden MeshCentral-Login-Cookie-Key auf dem MeshCentral-Host über
   den offiziellen, installierten 1.2.5-Code ausgeben und unmittelbar in die
   geschützte Datei schreiben:

   ```bash
   umask 077
   node /opt/meshcentral/node_modules/meshcentral/meshcentral.js \
     --logintokenkey > /run/railtime-meshcentral-secrets/meshcentral-login-key
   ```

   Die Datei muss exakt 160 Hex-Zeichen (80 Byte) enthalten. Dieser Schlüssel
   ist hochprivilegiert. `login_user` muss ein eigenes MeshCentral-Konto sein,
   das nur die benötigte Gerätegruppe und Remote-/Dateirechte besitzt; der Key
   darf nie in RailTime, Git, Logs oder Backups ohne Secret-Schutz landen.

Die Konfiguration akzeptiert keine Passwortfelder. Relative Pfade werden gegen
den Ordner der JSON-Datei aufgelöst. Auf Linux bricht der Start bei zu offenen
Dateirechten ab. Unter Windows kann Node POSIX-Modi nicht zuverlässig prüfen;
für den vorgesehenen Plesk-Linux-Betrieb ist die Prüfung vollständig aktiv.

## MeshCentral vorbereiten

- MeshCentral-Server ebenfalls auf die im Labor freigegebene Version 1.2.5
  pinnen und nicht automatisch auf `latest` aktualisieren.
- Die öffentliche Instanz unter `https://support.<domain>` mit gültigem TLS in
  Plesk veröffentlichen.
- Denselben Dienst zusätzlich ausschließlich lokal per WSS erreichbar machen,
  zum Beispiel `wss://127.0.0.1:8443`; diese Adresse steht in `url`.
- Eine eigene Gerätegruppe für RailTime-Geräte anlegen.
- Ein separates Konto `railtime-connector` nur für diese Gruppe berechtigen.
  Für Health, Diagnose, Upload und RunCommand werden nur die jeweils minimalen
  Lese-, Datei-, Agent-Console- und Remote-Control-Rechte benötigt. Invite- und
  Power-Rechte gehören nicht zum Connector-Servicekonto.

### Sicheres Bestandsgeräte-Onboarding

1. Den MeshAgent separat über das freigegebene MDM/UEM oder unter sichtbarer
   administrativer Aufsicht vorinstallieren; der RailTime-Connector erzeugt
   dafür keinen generischen Gruppeninvite.
2. In MeshCentral prüfen, dass der Agent in der vorgesehenen Gerätegruppe
   online ist, und seine **native Node-ID** erfassen.
3. Die Node-ID im autorisierten RailTime-Geräteprozess als MeshCentral-
   Support-Link zum bereits eindeutig inventarisierten Gerät hinterlegen.
   Erst ein aktiver `device_provider_links`-Datensatz darf Remote-Support,
   Diagnose oder Skriptausführung adressieren.
4. Geräteidentität, Mitarbeiterzuordnung und Node-ID im Vier-Augen-Prinzip
   abgleichen und den Vorgang auditieren. Es gibt keine automatisch behauptete
   `enrollment.completed`-Quittung.

Die autorisierte RailTime-Geräteansicht bietet dafür unter
`Provider-Verknüpfungen` den Dialog `Verknüpfen`. Er validiert native Mesh-IDs,
protokolliert die Bindung und ersetzt eine abweichende bestehende ID niemals
still. Direkte Datenbankeingriffe sind kein Produktionsweg.

## Plesk-/Docker-Start ohne ENV

Der folgende Betrieb nutzt das Host-Netzwerk, damit die erzwungene
Loopback-Verbindung sowohl MeshCentral als auch den RailTime-Adapter auf
demselben Plesk-Host erreicht. Es werden keine `-e`-Optionen verwendet.

```bash
cd /var/www/vhosts/<domain>/httpdocs/services/device-connectors/meshcentral
docker build --pull -t railtime-meshcentral-connector:1.0.0 .

install -d -m 0700 -o 1000 -g 1000 \
  /srv/railtime-meshcentral-connector/state \
  /srv/railtime-meshcentral-connector/tmp \
  /srv/railtime-meshcentral-connector/secrets
install -d -m 0750 /srv/railtime-meshcentral-connector/config
install -m 0600 -o 1000 -g 1000 config.example.json \
  /srv/railtime-meshcentral-connector/config/config.json

docker run -d --name railtime-meshcentral-connector \
  --restart unless-stopped \
  --network host \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges \
  --pids-limit 128 \
  --memory 256m \
  --cpus 1 \
  -v /srv/railtime-meshcentral-connector/config/config.json:/etc/railtime-meshcentral-connector/config.json:ro \
  -v /srv/railtime-meshcentral-connector/secrets:/run/railtime-meshcentral-secrets:ro \
  -v /srv/railtime-meshcentral-connector/state:/var/lib/railtime-meshcentral-connector/state \
  -v /srv/railtime-meshcentral-connector/tmp:/var/lib/railtime-meshcentral-connector/tmp \
  railtime-meshcentral-connector:1.0.0
```

Da der Connector selbst auf `127.0.0.1:9442` lauscht, wird **kein öffentlicher
Firewallport und keine Connector-Subdomain** benötigt. In RailTime wird
Plesk-Betriebsart **privater Adapterport**, Providerport **9442** gewählt. Die
öffentliche `support.<domain>`-Subdomain gehört MeshCentral selbst, nicht diesem
Adapter.

Falls RailTime für alle Provider global im Subdomainmodus läuft, darf Plesk
alternativ eine eigene Connector-Origin wie `https://support-connector.<domain>`
per nginx ausschließlich auf `http://127.0.0.1:9442` weiterleiten. Diese
Connector-Origin ist nicht mit der öffentlichen MeshCentral-Browser-Origin zu
verwechseln und benötigt ebenfalls ein gültiges Zertifikat; Port `9442` bleibt
in der Firewall geschlossen.

Falls Docker-Hostnetwork in der Plesk-Erweiterung nicht verfügbar ist, denselben
Start als systemd-Service mit Node 22 ausführen:

```ini
[Service]
Type=simple
User=railtime-device
Group=railtime-device
ExecStart=/usr/bin/node /var/www/vhosts/<domain>/httpdocs/services/device-connectors/meshcentral/src/server.js --config /etc/railtime-meshcentral-connector/config.json
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/railtime-meshcentral-connector
```

## Prüfung

```bash
npm ci --ignore-scripts --no-audit --no-fund
npm test
node -p "require('./node_modules/meshcentral/package.json').version"
```

Der letzte Befehl muss `1.2.5` ausgeben. Danach in RailTime ausschließlich
**Verbindung prüfen** verwenden; dies ruft nur `GET /v1/health` auf. Erst nach
dem dokumentierten Laborlauf den globalen Mutationsschalter aktivieren.

Laborreihenfolge:

1. Health mit absichtlich falschem und richtigem Token.
2. `POST /v1/enrollments` liefert `409 enrollment_not_supported`, ohne
   `meshctrl.js` aufzurufen. Ein `restart`-Befehl liefert `422`.
3. Einen bereits installierten Laboragenten anhand seiner nativen Node-ID
   kontrolliert mit dem RailTime-Gerät verknüpfen und `DeviceInfo` prüfen.
4. Geprüftes harmloses Skript mit SHA-Nachweis sowie absichtlich verändertes
   Zielartefakt; beim Fehler dürfen weder Ausführung noch Staging-Datei bleiben.
5. Timeout, Offlinegerät, doppelter Request, geänderte Payload bei gleicher
   `correlation_id` und beschädigtes Artefakt testen.
6. State-, Secret- und MeshCentral-Backup wiederherstellen und Health erneut
   belegen.

## Offizielle Referenzen (geprüft am 23.08.2026)

- [MeshCentral Releases – 1.2.5 vom 12.08.2026](https://github.com/Ylianst/MeshCentral/releases/tag/1.2.5)
- [Offizielle meshctrl.js 1.2.5](https://github.com/Ylianst/MeshCentral/blob/1.2.5/meshctrl.js)
- [Offizielle package.json 1.2.5](https://github.com/Ylianst/MeshCentral/blob/1.2.5/package.json)
- [MeshCtrl Login-Key-Verwendung im offiziellen Repository](https://github.com/Ylianst/MeshCentral/blob/1.2.5/meshctrl.js#L76-L84)

## Nicht automatisch erledigt

Der Connector installiert oder konfiguriert weder MeshCentral noch Plesk-TLS,
legt keine MeshCentral-Konten an und schaltet RailTimes Produktionsbefehle nicht
frei. Echte Produktionsbereitschaft ist erst nach Providerkonto,
Laborgeräten, Rechteprüfung, Backup/Restore, Monitoring und dokumentiertem
Go-live-Gate gegeben.
