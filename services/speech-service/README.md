# LMZ Speech Service

Privater, gemeinsamer STT-/TTS-Dienst fuer RailTime und Followflow. Der Dienst
verwendet nur die Python-Standardbibliothek und bindet fest an `127.0.0.1`.
Eine andere Bind-Adresse ist weder per Config noch per Kommandozeile moeglich.

Die Engine-Vertraege entsprechen der bestehenden Followflow-Laufzeit:

- ffmpeg konvertiert nach Mono, 16 kHz, PCM-S16LE-WAV;
- whisper.cpp wird mit `-m`, `-f`, `-nt`, `-l` und optional `-t` aufgerufen;
- Piper verwendet `--length-scale`, `--input-file` und `--output-file`;
- der optionale Piper-Modus `legacy` verwendet `--length_scale`,
  `--output_file` und Text ueber stdin.

Es gibt keine Python-Pakete zu installieren und keinen oeffentlichen
Webserver-Proxy. Beide Laravel-Anwendungen sprechen den Dienst serverseitig
ueber `http://127.0.0.1:8092` an.

## API-Vertrag

Alle Antworten enthalten `Cache-Control: no-store`,
`X-Content-Type-Options: nosniff` und `X-Request-ID`. Ein gueltiges,
hoechstens 64 Zeichen langes `X-Request-ID` wird uebernommen; andernfalls
erzeugt der Dienst eine UUID.

### `GET /healthz`

Keine Authentifizierung. Die Antwort enthaelt absichtlich weder Config- noch
Runtime-Pfade:

```json
{"status":"ok"}
```

Der Health-Check ist nur ein Liveness-Check. Die Engine-Bereitschaft steht im
authentifizierten Status.

### Authentifizierung

Alle `/v1/*`-Endpunkte verlangen beide Header:

```http
X-Client-ID: railtime
Authorization: Bearer KLARTEXT_TOKEN_DES_CLIENTS
```

Die Service-Config speichert ausschliesslich den SHA-256-Hash des Tokens. Der
Dienst hasht das eingehende Token und vergleicht beide Digests mit
`hmac.compare_digest`. Fehlender Client, unbekannter Client und falsches Token
erhalten dieselbe `401`-Antwort.

### `GET /v1/status`

Beispielantwort ohne Dateipfade:

```json
{
  "status": "ready",
  "engines": {
    "ffmpeg": "ready",
    "whisper": "ready",
    "piper": "ready"
  },
  "limits": {
    "max_body_bytes": 12000000,
    "max_audio_bytes": 8388608,
    "max_audio_seconds": 60.0,
    "max_text_chars": 4000,
    "max_transcript_chars": 8000,
    "max_output_audio_bytes": 33554432
  },
  "concurrency": {
    "whisper": 1,
    "piper": 1,
    "http_threads": 8,
    "queue_wait_seconds": 3.0
  },
  "request_id": "95aebcd0-4220-448d-a1cb-3288373da13d"
}
```

`status` ist `degraded`, sobald mindestens eine Engine nicht bereit ist.

### `POST /v1/transcriptions`

`Content-Type: application/json` und exakt dieses Schema:

```json
{
  "audio_base64": "GkXfo59ChoEBQveBAULygQ...",
  "filename": "recording.webm",
  "mime_type": "audio/webm;codecs=opus",
  "language": "de-DE"
}
```

- Alle vier Felder sind erforderlich; unbekannte Felder werden abgewiesen.
- `filename` darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich
  enthalten. Er wird nie als Dateipfad verwendet.
- Erlaubte Basistypen: Audio-/Video-WebM, OGG, WAV, MPEG/MP3 und
  Audio-/Video-MP4/M4A. Video-Container werden nur als Audioquelle verarbeitet.
- whisper.cpp erhaelt aus `de-DE` den primaeren Sprachcode `de`.
- Base64 wird strikt validiert. Groesse und WAV-Dauer werden serverseitig
  begrenzt.

Erfolgsantwort:

```json
{
  "text": "Der erkannte Text.",
  "request_id": "95aebcd0-4220-448d-a1cb-3288373da13d"
}
```

### `POST /v1/speech`

`Content-Type: application/json` und exakt dieses Schema:

```json
{
  "text": "Dieser Text wird vorgelesen.",
  "speed": 1.0
}
```

`speed` muss zwischen `0.5` und `2.0` liegen. Die Erfolgsantwort ist
`audio/wav`; die Korrelations-ID steht im Header `X-Request-ID`.

### Fehler

Fehler enthalten keine Engine-Ausgabe, Nutztexte, Transkripte, Tokens oder
Pfade:

```json
{
  "error": {
    "code": "invalid_audio_base64",
    "message": "audio_base64 is not valid base64."
  },
  "request_id": "95aebcd0-4220-448d-a1cb-3288373da13d"
}
```

Relevante Statuscodes: `400`, `401`, `408`, `411`, `413`, `415`, `422`,
`503` und `504`. Bei einer belegten Engine liefert der Dienst `503` plus
`Retry-After: 1`.

## Config-Schema

[`config.example.json`](config.example.json) zeigt das vollstaendige Schema.
Die dortigen Token-Hashes sind absichtlich ungueltige Platzhalter. Der Dienst
startet damit nicht. Beide Werte muessen durch die getrennt erzeugten
64-stelligen SHA-256-Hashes ersetzt werden; doppelte Hashes werden ebenfalls
abgewiesen.

Die Service-[`.gitignore`](.gitignore) blockiert lokale `config.json`-,
`service.env`-, `.env`-, `*.token`-, `*.secret`- und Secret-Verzeichnisdateien.
`config.example.json` sowie alle versionierten Deployment-Skripte bleiben
dagegen ausdrücklich sichtbar. Produktive Konfiguration und Tokens gehören
trotzdem immer außerhalb des Checkouts an die unten beschriebenen Orte; der
Ignore ist nur die letzte Schutzschicht gegen versehentliches Versionieren.

`command` ist immer ein Argument-Array. Der Dienst verwendet niemals eine
Shell. Dadurch koennen auch Aufrufe wie `[/pfad/python3, -m, piper]` sicher
konfiguriert werden. Alle Modell-, Config- und Temp-Pfade muessen absolut sein.
Der Port ist optional und standardmaessig `8092`; die Bind-Adresse ist nicht
konfigurierbar.

`http_threads` begrenzt zusaetzlich die gleichzeitig aktiven HTTP-Requests;
weitere Verbindungen erhalten sofort `503 server_busy`. Die Whisper- und
Piper-Semaphoren sowie die kurze Queue gelten zentral fuer alle HTTP-Threads
eines Prozesses. Deshalb muss Supervisor genau einen Prozess (`numprocs=1`)
starten.

## Sichere Plesk-Installation mit Supervisor

Der Dienst läuft unter dem eigenen, nicht interaktiven Benutzer `lmz-speech`.
Er besitzt keine Schreibrechte in RailTime oder Followflow. Die geprüften
Runtime-Dateien werden in einen separaten, nur lesbaren Bestand kopiert; sie
werden nicht aus einem App-Verzeichnis verlinkt oder dort verschoben. Damit
bleibt ein Fehler in ffmpeg, Whisper oder Piper auf den Dienstbereich begrenzt.

### 1. Dienstkonto und unveränderbare Elternverzeichnisse

Gruppe und Benutzer einmalig anlegen. Alle Eltern-, Runtime- und
Logverzeichnisse bleiben `root`-owned. Nur das einzelne Temp-Unterverzeichnis
gehört dem Dienst. Dadurch kann ein kompromittierter Dienst weder den
root-owned Runtime-Baum umbenennen/ersetzen noch Supervisor über eine
ausgetauschte Logdatei oder einen Symlink auf ein fremdes Ziel lenken.

```bash
set -euo pipefail

getent group lmz-speech >/dev/null || groupadd --system lmz-speech
id -u lmz-speech >/dev/null 2>&1 || useradd --system --gid lmz-speech --home-dir /var/lib/lmz-speech --shell /usr/sbin/nologin lmz-speech

for protected_parent in /opt/lmz-speech /etc/lmz-speech /var/lib/lmz-speech /var/log/lmz-speech; do
  if [ -L "$protected_parent" ]; then
    printf 'Refusing symlink: %s\n' "$protected_parent" >&2
    exit 1
  fi
done
install -d -o root -g lmz-speech -m 750 /opt/lmz-speech /etc/lmz-speech /var/lib/lmz-speech /var/log/lmz-speech

for protected_child in /opt/lmz-speech/releases /var/lib/lmz-speech/runtime /var/lib/lmz-speech/tmp; do
  if [ -L "$protected_child" ]; then
    printf 'Refusing symlink: %s\n' "$protected_child" >&2
    exit 1
  fi
done
install -d -o root -g lmz-speech -m 750 /opt/lmz-speech/releases /var/lib/lmz-speech/runtime
install -d -o lmz-speech -g lmz-speech -m 700 /var/lib/lmz-speech/tmp

if [ -L /var/log/lmz-speech/service.log ] || { [ -e /var/log/lmz-speech/service.log ] && [ ! -f /var/log/lmz-speech/service.log ]; }; then
  printf 'Refusing unsafe log path: %s\n' /var/log/lmz-speech/service.log >&2
  exit 1
fi
if [ ! -e /var/log/lmz-speech/service.log ]; then
  install -o root -g lmz-speech -m 640 /dev/null /var/log/lmz-speech/service.log
else
  chown --no-dereference root:lmz-speech /var/log/lmz-speech/service.log
  chmod 640 /var/log/lmz-speech/service.log
fi
```

Bei einem vorhandenen Konto UID, primäre Gruppe und `nologin`-Shell trotzdem
prüfen. Die Sicherheitsgrenze anschließend ausdrücklich verifizieren:

```bash
stat -c '%U:%G %a %n' \
  /var/lib/lmz-speech \
  /var/lib/lmz-speech/runtime \
  /var/lib/lmz-speech/tmp \
  /var/log/lmz-speech \
  /var/log/lmz-speech/service.log
sudo -u lmz-speech test ! -w /var/lib/lmz-speech
sudo -u lmz-speech test ! -w /var/lib/lmz-speech/runtime
sudo -u lmz-speech test ! -w /var/log/lmz-speech
sudo -u lmz-speech test -w /var/lib/lmz-speech/tmp
```

Erwartet werden `root:lmz-speech 750` für die drei geschützten Verzeichnisse,
`lmz-speech:lmz-speech 700` für `tmp` und `root:lmz-speech 640` für das Log.

### 2. Release und Runtime bereitstellen

Den Python-Dienst in ein unveränderbares Release kopieren. Der
Provisionierungshelfer ist absichtlich global lesbar/ausführbar: Er besitzt
keine Privilegien und wird später als der jeweilige Plesk-PHP-Benutzer
ausgeführt.

```bash
set -euo pipefail

release="$(date -u +%Y%m%dT%H%M%SZ)"
release_dir="/opt/lmz-speech/releases/$release"
next_link="/opt/lmz-speech/.current-$release"

install -d -o root -g lmz-speech -m 750 "$release_dir"
install -o root -g lmz-speech -m 640 speech_service.py "$release_dir/speech_service.py"
install -d -o root -g root -m 755 /usr/local/libexec/lmz-speech
install -o root -g root -m 755 deploy/provision_client_token.py /usr/local/libexec/lmz-speech/provision_client_token.py
ln -s "$release_dir" "$next_link"
mv -Tf "$next_link" /opt/lmz-speech/current
```

Die bestehenden ffmpeg-/Whisper-/Piper-Binaries und Modelle in
`/var/lib/lmz-speech/runtime` **kopieren**, rekursiv auf
`root:lmz-speech` setzen und alle Schreibrechte für Gruppe und Andere
entfernen. Ausführbare Engine-Dateien benötigen `750`, Modelle und
Konfigurationen `640`. Nichts aus einem App-Verzeichnis verlinken. Danach den
gesamten Runtime-Baum fail-closed prüfen:

```bash
set -euo pipefail
runtime_symlink="$(find /var/lib/lmz-speech/runtime -type l -print -quit)"
if [ -n "$runtime_symlink" ]; then
  printf 'Refusing symlink in speech runtime\n' >&2
  exit 1
fi
runtime_writable="$(sudo -u lmz-speech find /var/lib/lmz-speech/runtime -writable -print -quit)"
if [ -n "$runtime_writable" ]; then
  printf 'Speech runtime is writable by lmz-speech\n' >&2
  exit 1
fi
```

### 3. Getrennte Plesk-Tokens innerhalb von `open_basedir`

RailTime und Followflow müssen unter verschiedenen Plesk-Systembenutzern und
damit verschiedenen Unix-UIDs laufen. Teilen beide Domains dieselbe UID, ist
eine gegenseitige Lesesperre per Dateirechten unmöglich; die Abonnements oder
PHP-Pools müssen dann vor der Aktivierung getrennt werden.

Die Token-Datei liegt jeweils außerhalb des Document Roots, aber innerhalb des
eigenen Plesk-`WEBSPACEROOT`. Damit bleibt sie beim Plesk-Standard
[`open_basedir={WEBSPACEROOT}{/}{:}{TMP}{/}`](https://docs.plesk.com/en-US/obsidian/administrator-guide/web-hosting/php-management/customizing-php-parameters.79190/)
lesbar, ohne `/etc` oder
`/run/secrets` für PHP freizugeben. Platzhalter vor Ausführung durch die realen
Plesk-Benutzer und Webspace-Wurzeln ersetzen:

```bash
set -euo pipefail

RAILTIME_PHP_USER='<railtime-plesk-system-user>'
FOLLOWFLOW_PHP_USER='<followflow-plesk-system-user>'
RAILTIME_WEBSPACE='/var/www/vhosts/<railtime-webspace>'
FOLLOWFLOW_WEBSPACE='/var/www/vhosts/<followflow-webspace>'

test "$(id -u "$RAILTIME_PHP_USER")" != "$(id -u "$FOLLOWFLOW_PHP_USER")"

RAILTIME_SECRET_DIR="$RAILTIME_WEBSPACE/.lmz-secrets"
FOLLOWFLOW_SECRET_DIR="$FOLLOWFLOW_WEBSPACE/.lmz-secrets"
RAILTIME_TOKEN_FILE="$RAILTIME_SECRET_DIR/speech-service.token"
FOLLOWFLOW_TOKEN_FILE="$FOLLOWFLOW_SECRET_DIR/speech-service.token"

sudo -u "$RAILTIME_PHP_USER" /usr/bin/install -d -m 700 "$RAILTIME_SECRET_DIR"
sudo -u "$FOLLOWFLOW_PHP_USER" /usr/bin/install -d -m 700 "$FOLLOWFLOW_SECRET_DIR"

sudo -u "$RAILTIME_PHP_USER" /usr/bin/python3 /usr/local/libexec/lmz-speech/provision_client_token.py --output "$RAILTIME_TOKEN_FILE"
sudo -u "$FOLLOWFLOW_PHP_USER" /usr/bin/python3 /usr/local/libexec/lmz-speech/provision_client_token.py --output "$FOLLOWFLOW_TOKEN_FILE"
```

Jeder Helferaufruf ersetzt die jeweilige Datei atomar mit Modus `600` und gibt
nur `token_sha256=<64-stelliger Hash>` aus. Den Klartext zeigt er nie an. Die
beiden Hashes in die passenden Client-Einträge der Service-Config übernehmen.
Danach sowohl die positive als auch die gegenseitige negative Leseregel prüfen:

```bash
sudo -u "$RAILTIME_PHP_USER" test -r "$RAILTIME_TOKEN_FILE"
sudo -u "$FOLLOWFLOW_PHP_USER" test -r "$FOLLOWFLOW_TOKEN_FILE"
sudo -u "$RAILTIME_PHP_USER" test ! -r "$FOLLOWFLOW_TOKEN_FILE"
sudo -u "$FOLLOWFLOW_PHP_USER" test ! -r "$RAILTIME_TOKEN_FILE"
```

Die beiden Laravel-Konfigurationen verwenden entsprechend ihren eigenen Pfad:

```dotenv
# RailTime
SPEECH_SERVICE_TOKEN_FILE=/var/www/vhosts/<railtime-webspace>/.lmz-secrets/speech-service.token

# Followflow
SPEECH_SERVICE_TOKEN_FILE=/var/www/vhosts/<followflow-webspace>/.lmz-secrets/speech-service.token
```

### 4. Service-Config und Umgebung

`config.example.json` nach `/etc/lmz-speech/config.json` kopieren, beide
Token-Platzhalter ersetzen, die Runtime-Pfade eintragen und als
`root:lmz-speech` mit Modus `640` ablegen. Die Config wird bewusst abgewiesen,
solange ein Platzhalter, ein ungültiger Hash oder derselbe Hash für beide Apps
enthalten ist.

`/etc/lmz-speech/service.env` mit genau einer Zeile als `root:lmz-speech` und
Modus `640` erstellen:

```dotenv
LMZ_SPEECH_CONFIG=/etc/lmz-speech/config.json
```

Alternativ kann Supervisor `LMZ_SPEECH_CONFIG` direkt als Prozessumgebung
setzen. Relative Pfade werden abgewiesen. Config und Runtime anschließend als
Dienstbenutzer prüfen:

```bash
sudo -u lmz-speech /usr/bin/python3 -I /opt/lmz-speech/current/speech_service.py --env-file /etc/lmz-speech/service.env --check-config
```

### 5. Supervisor aktivieren und jedes Release neu starten

[`deploy/supervisor.conf.example`](deploy/supervisor.conf.example) prüfen und
in die vorhandene Supervisor-Konfiguration aufnehmen. Nach **jedem** atomaren
Umschalten von `/opt/lmz-speech/current` ist die folgende vollständige Sequenz
erforderlich:

```bash
supervisorctl reread
supervisorctl update
supervisorctl restart lmz-speech-service
supervisorctl status lmz-speech-service
```

`reread`/`update` allein laden einen neuen Symlink-Inhalt bei unveränderter
Programmkonfiguration nicht zuverlässig in den bereits laufenden
Python-Prozess. Der explizite `restart` ist deshalb Teil jedes Deployments. Es
wird bewusst keine systemd-Unit mitgeliefert. Supervisor startet Python mit
`-I`, einem absoluten Scriptpfad und root-owned `HOME`; dadurch können weder
eine beschreibbare User-Site noch `PYTHON*`-Umgebungsvariablen Code vor dem
unveränderbaren Release einschleusen. Nur `TMPDIR` zeigt auf das beschreibbare
Temp-Verzeichnis.

### 6. Laufzeit prüfen

Es darf nur `127.0.0.1:8092` erscheinen:

```bash
ss -ltnp | grep ':8092'
ps -o user,group,cmd -C python3 | grep lmz-speech
curl --fail --silent http://127.0.0.1:8092/healthz
```

Keinen Apache-, nginx- oder Plesk-Reverse-Proxy auf Port `8092` einrichten und
keine Firewall-Freigabe anlegen. Der Port ist ausschliesslich fuer lokale
Server-zu-Server-Aufrufe vorgesehen.

## Betrieb und Sicherheit

- Logs enthalten nur Methode, bekannten Endpunkt, Status, Korrelations-ID und
  Dauer. Request-Body, Text, Transkript, Token, Engine-Ausgabe und Pfade werden
  nicht geloggt.
- Temp-Verzeichnisse werden je Request mit restriktiven Rechten erzeugt und
  auch bei Fehlern oder Timeouts automatisch entfernt.
- ffmpeg, Whisper und Piper haben getrennte Timeouts. Audio-, Body-, Dauer-,
  Text-, Transkript- und Ausgabegrenzen werden unabhaengig erzwungen.
- Tokenrotation: den Helfer erneut als den betroffenen Plesk-PHP-Benutzer
  ausführen, den neuen Hash in der root-owned Config speichern und den einen
  Supervisor-Prozess neu starten. Die zweite App behält dabei ihr eigenes
  unverändertes Token.
- `/healthz` belegt nur, dass HTTP antwortet. Monitoring der Engines muss den
  authentifizierten `/v1/status`-Endpunkt verwenden.

## Lokale Tests

Die Tests starten den Server nur auf einem zufaelligen Loopback-Port und
verwenden gemockte Engines:

```bash
python3 -m unittest discover -s tests -v
python3 -m py_compile speech_service.py deploy/provision_client_token.py
```
