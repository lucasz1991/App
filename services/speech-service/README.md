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
    "max_body_bytes": 29000000,
    "max_audio_bytes": 20971520,
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
Die dortigen Token-Hashes sind zufaellige, nicht nutzbare Beispielwerte und
muessen ersetzt werden.

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

1. Dienstbenutzer und private Verzeichnisse ausserhalb aller Document Roots
   anlegen:

   ```bash
   useradd --system --home-dir /var/lib/lmz-speech --shell /usr/sbin/nologin lmz-speech
   install -d -o root -g lmz-speech -m 750 /opt/lmz-speech /etc/lmz-speech
   install -d -o lmz-speech -g lmz-speech -m 700 /var/lib/lmz-speech /var/lib/lmz-speech/tmp
   install -d -o root -g lmz-speech -m 750 /var/lib/lmz-speech/runtime
   install -d -o lmz-speech -g lmz-speech -m 750 /var/log/lmz-speech
   ```

   Falls der Benutzer bereits existiert, entfällt nur `useradd`; UID, Gruppen
   und Rechte müssen trotzdem geprüft werden.

2. `speech_service.py` versioniert nach `/opt/lmz-speech/releases/<release>/`
   kopieren und `/opt/lmz-speech/current` atomar auf das geprüfte Release
   umschalten. Die bestehenden Followflow-Binaries und Modelle in
   `/var/lib/lmz-speech/runtime` **kopieren**, Eigentümer `root:lmz-speech`
   setzen und Schreibrechte für `lmz-speech` entfernen.

3. `config.example.json` nach `/etc/lmz-speech/config.json` kopieren, die
   separaten Runtime-Pfade eintragen und als `root:lmz-speech` mit Modus `640`
   ablegen.

4. Pro Client ein langes, zufaelliges Token erzeugen. Das Klartexttoken kommt
   nur in den jeweiligen Laravel-Secret-Store; den Hash interaktiv erzeugen:

   ```bash
   /usr/bin/python3 speech_service.py --hash-token
   ```

5. `/etc/lmz-speech/service.env` mit genau einer Zeile als
   `root:lmz-speech` und Modus `640` erstellen:

   ```dotenv
   LMZ_SPEECH_CONFIG=/etc/lmz-speech/config.json
   ```

   Alternativ kann Supervisor `LMZ_SPEECH_CONFIG` direkt als
   Prozessumgebung setzen. Relative Pfade werden abgewiesen.

6. Config und Runtime als Dienstbenutzer prüfen:

   ```bash
   sudo -u lmz-speech /usr/bin/python3 /opt/lmz-speech/current/speech_service.py --env-file /etc/lmz-speech/service.env --check-config
   ```

7. [`deploy/supervisor.conf.example`](deploy/supervisor.conf.example) prüfen,
   in die vorhandene
   Supervisor-Konfiguration aufnehmen und mit `supervisorctl reread`,
   `supervisorctl update` und `supervisorctl status lmz-speech-service`
   aktivieren. Es wird bewusst keine systemd-Unit mitgeliefert.

8. Bindung und Prozessbenutzer kontrollieren. Es darf nur
   `127.0.0.1:8092` erscheinen:

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
- Tokenrotation: neuen Hash speichern, Config atomar ersetzen und den einen
  Supervisor-Prozess neu starten; danach das Klartexttoken im jeweiligen
  Laravel-Projekt aktualisieren.
- `/healthz` belegt nur, dass HTTP antwortet. Monitoring der Engines muss den
  authentifizierten `/v1/status`-Endpunkt verwenden.

## Lokale Tests

Die Tests starten den Server nur auf einem zufaelligen Loopback-Port und
verwenden gemockte Engines:

```bash
python3 -m unittest discover -s tests -v
python3 -m py_compile speech_service.py
```
