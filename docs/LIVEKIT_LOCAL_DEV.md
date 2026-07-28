# LiveKit lokal für die Entwicklung

Für die Produktion gilt `SERVER_SETUP.md` (eigene Media-VM). Dieses Dokument
beschreibt nur den **lokalen Entwicklungsserver**, mit dem sich Anrufe zwischen
zwei Browserfenstern testen lassen, bevor die VM existiert.

Die Dateien liegen bewusst **außerhalb des Repositories**: laut
`LIVEKIT_INTEGRATION_PLAN.md` (D2) bleiben Repo und Plesk-Host Docker-frei,
Docker läuft ausschließlich auf der Media-VM.

## 1. Ablageort anlegen

Beliebiger Ordner außerhalb des Projekts, z. B. `C:\livekit-dev\`.

`livekit.yaml`:

```yaml
port: 7880
bind_addresses: ["0.0.0.0"]

rtc:
  # Schluesselnamen verifiziert gegen livekit-server v1.8.4.
  # udp_port_range_* existiert NICHT und bricht den Start ab.
  # Kleiner Bereich, weil 10.000 gemappte UDP-Ports unter Docker
  # Desktop/Windows unbrauchbar langsam sind.
  port_range_start: 50000
  port_range_end: 50040
  tcp_port: 7881
  use_external_ip: false

keys:
  # Nur lokal. Das Secret muss mindestens 32 Zeichen lang sein.
  devkey: "railtime-local-dev-secret-0123456789"

webhook:
  api_key: devkey
  urls:
    # host.docker.internal = der Windows-Host, auf dem XAMPP die App ausliefert.
    - http://host.docker.internal:5000/webhooks/livekit

logging:
  level: info
```

`docker-compose.yml`:

```yaml
services:
  livekit:
    image: livekit/livekit-server:v1.8
    command: --config /etc/livekit.yaml
    volumes:
      - ./livekit.yaml:/etc/livekit.yaml:ro
    ports:
      - "7880:7880"
      - "7881:7881"
      - "50000-50040:50000-50040/udp"
    restart: unless-stopped
```

## 2. Starten

```bash
docker compose up -d
```

Prüfen: `docker compose logs` muss `starting LiveKit server` zeigen, und
`curl http://localhost:7880/` muss `HTTP 200` liefern.

## 3. App auf den lokalen Server zeigen lassen

In der lokalen `.env`:

```dotenv
LIVEKIT_URL=http://localhost:7880
LIVEKIT_WS_URL=ws://localhost:7880
LIVEKIT_API_KEY=devkey
LIVEKIT_API_SECRET=railtime-local-dev-secret-0123456789
LIVEKIT_TURN_MODE=embedded
```

Danach:

```bash
php artisan config:clear
php artisan railtime:livekit-check
```

> Die für die Produktion erzeugten Schlüssel (`railtime:livekit-keys`) werden
> dabei überschrieben. Das ist unkritisch: laut `SERVER_SETUP.md` §3.1 werden
> sie ohnehin **auf dem Plesk-Server** erzeugt, nicht lokal.

## 4. Grenzen des lokalen Aufbaus

- **Kein TLS.** `ws://localhost` funktioniert, weil `localhost`/`127.0.0.1` ein
  sicherer Kontext ist — Kamera und Mikrofon werden freigegeben. Von einem
  anderen Gerät im LAN aus geht es deshalb *nicht*.
- **Kein TURN-Test.** Der Firewall-Fallback lässt sich lokal nicht sinnvoll
  prüfen; das braucht die echte VM (Portmatrix in `SERVER_SETUP.md`).
- **Webhooks** erreichen die App nur, wenn XAMPP wirklich auf Port 5000 lauscht
  und die Windows-Firewall `host.docker.internal` durchlässt. Bleiben Räume
  nach dem Auflegen auf `active`, ist genau das die Ursache.

## 5. Stoppen

```bash
docker compose down
```
