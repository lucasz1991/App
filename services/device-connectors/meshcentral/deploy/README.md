# MeshCentral: isolierter Produktions-Bootstrap

Diese Dateien bereiten **nur** eine neue, lokale Docker-Installation vor. Kein
Serverstart, keine öffentliche Route, keine Benutzer/Passwörter/Secrets und kein
Windows-Agent werden automatisch erzeugt. Sie sind kein Live-Betriebsnachweis.

Ziel: Linux/amd64, Docker Engine mit Compose v2, Python >=3.11; MeshCentral
1.2.5 und der vorhandene RailTime-Connector aus demselben npm-Lock. Node22 kommt
aus dem Container, nicht aus Plesks Node24/26. `qs` muss im Manifest und allen
Lock-Einträgen exakt6.16.0 sein. Alle npm-Pakete brauchen Registry-Herkunft und
SHA512-Integrität. Optionale SMTP/SSO/SSH/Recording/Captcha/Plugin-Funktionen
bleiben aus; deren Abhängigkeiten sind nicht vollständig in diesem Image.

## Lokaler Ablauf nach Root-Review, noch ohne öffentliche Freigabe

Aus dem Connector-Quellordner auf dem **Zielhost** ausführen. `/srv` muss bereits
existieren; `/srv/railtime-meshcentral-pilot-20260906` darf noch nicht existieren. Kein
ENV-File verwenden. Der am06.09.2026 separat am Zielserver geprüfte offizielle
Node22-Multiarch-Index lautet:

`node:22-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5`

Der Digest ist ein **verpflichtendes Argument**, kein versteckter Default.
Registry/Plattform vor späterer Wiederverwendung erneut prüfen.

```bash
python3 deploy/bootstrap.py init --root /srv/railtime-meshcentral-pilot-20260906 --base-image node:22-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5
sudo python3 deploy/bootstrap.py init --root /srv/railtime-meshcentral-pilot-20260906 --base-image node:22-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5 --apply
sudo python3 deploy/bootstrap.py validate --root /srv/railtime-meshcentral-pilot-20260906 --before-start
sudo docker compose --env-file /dev/null -f /srv/railtime-meshcentral-pilot-20260906/compose.json config --quiet
sudo docker compose --env-file /dev/null -f /srv/railtime-meshcentral-pilot-20260906/compose.json build meshcentral
sudo docker compose --env-file /dev/null -f /srv/railtime-meshcentral-pilot-20260906/compose.json up -d --no-build meshcentral
```

Die letzten beiden Befehle sind **separate explizite Betriebsaktionen**, nicht
Teil des Helpers. Vor `up` muss der gerade gebaute Imagebestand geprüft sein;
kein fremdes gleichnamiges Image verwenden. `--apply` setzt UID/GID1000 und
0700/0600 nur an neu angelegten eigenen Pfaden. Bei Fehlern bleibt eine eventuell
teilweise angelegte Struktur erhalten; kein automatisches Löschen/Überschreiben.
Dry-run und Validierung verändern keine Dateien. Die Portprüfung bindet kurz
einen noch freien Loopback-Port, ohne Listener oder Clientverbindung zu starten.
Zwischen Prüfung und Start kann ein Port belegt werden; `exactPorts:true`
verhindert dann das stille Ausweichen auf einen anderen Port.

Beide Container sind unprivilegiert, Root-Dateisystem read-only, alle Capabilities
entzogen, `no-new-privileges`, begrenzte CPU/RAM/PIDs und lokale Logrotation.
Host-Netzwerk ist ausschließlich wegen des bestehenden Connector-WSS-Vertrags
nötig; es isoliert **nicht** das Hostnetz. Native Anwendung bindet fest
127.0.0.1:8444, Adapter127.0.0.1:9442; Plesk8443 bleibt unverändert.
Separate persistente Pfade: `native/data`, `native/files`, `native/backups`;
Native-Konfiguration ist zusätzlich read-only eingebunden. Keine Ports werden
von Compose veröffentlicht. Ausschließlich root kontrolliert den privaten
Deployordner und die Compose-Datei; Quell-/Lockänderungen nach Initialisierung
werden anhand des Manifests abgewiesen. Dieser Fingerprint ersetzt keine Signatur
gegen einen Angreifer mit Rootrechten.

## Connector bleibt gesperrt

Das Profil `connector` ist opt-in; fehlende Secret-Dateien verhindern den Start.
In `connector/secrets` müssen später drei **nicht öffentlich auszugebende**, zum
Container gehörende0600/0400-Dateien hinterlegt werden: `railtime-bearer-token`,
`railtime-hmac-secret`, `meshcentral-login-key`. Erstere mindestens32Zeichen;
LoginKey exakt160Hex-Zeichen. Der Helper prüft nur begrenzt deren Format, niemals
die Anmeldung oder Gleichheit mit RailTime. Er erzeugt oder druckt keine Werte.

Der MeshCentral-Key ist serverweit privilegiert, kein auf eine Gruppe
beschränkter API-Key. Auf einem Bestandsserver nicht neu generieren: das widerruft
andere Integrationen. Separates Servicekonto zunächst nur für genau die
Pilotgruppe. RemoteCommands131072 ist für PowerShell erforderlich; kein
Siteadmin/Invite/Power-Recht. Die Konfiguration deaktiviert Skripte und
Fernsupport zunächst; nur Health/Diagnose sind vorbereitet.

Nach geschützter Einrichtung kann die separat freizugebende Diagnosestufe mit
`validate --connector --before-start` geprüft und mit dem ausdrücklichen
Compose-Profil `--profile connector` gebaut/gestartet werden. Diese Prüfung
blockiert Änderungen der Bootstrap-Konfiguration bewusst; für die späteren
freigegebenen Funktions-/Proxyänderungen ist eine neue dokumentierte Prüfstufe
nötig, kein Entfernen der Schutzprüfung.

## Offene Gates vor Öffentlichkeit oder Windows-Installation

1. Echter Linux-Docker-Build/Start, Version1.2.5/Node22 und alle benötigten
   Abhängigkeiten belegen; aktuelles Security-Audit, Persistenz nach Neustart,
   Ressourcen und Backup/Restore prüfen. Lokale Unit-Tests ersetzen das nicht.
2. DNS und gültiges Zertifikat für `support.app.rail-time.de` bereitstellen.
   Zielserver nutzt **Apache**, nicht nginx. Deshalb wird absichtlich keine
   Proxydatei geliefert. Später HTTPS-Upstream mit verifizierter nativer CA,
   Peernameprüfung, WebSocket/Timeouts und festen Forwarded-Headern einrichten.
3. Aktuell kein `certUrl`/`trustedProxy`: verhindert ein falsches Zertifikat vom
   noch nicht eingerichteten öffentlichen vHost. Erst wenn Apache auf443 per
   SNI das richtige Subdomain-Zertifikat liefert, `certUrl` auf diesen lokalen
   TLS-Endpunkt und `trustedProxy` auf den exakten lokalen Proxy setzen.
   Upstream-TLS bleibt aktiv. Niemals Agent-Hashprüfung deaktivieren.
4. First-Admin zuerst über geschützten, zeitlich begrenzten Zugang einrichten,
   anschließend2FA. `newAccounts:false` allein schützt bei leerem Benutzerbestand
   **nicht** vor einem fremden ersten Administrator. Noch keine öffentliche
   Freigabe/automatische Kontenanlage aus diesem Bootstrap ableiten.
5. Nur den freigegebenen Pilot-PC administrativ installieren, echte Node-ID und
   Identität prüfen, als Support-Link am bestehenden RailTime-Gerät binden;
   nicht die Microsoft-Primärverknüpfung ersetzen. Gruppe/Download sind keine
   einmalige gerätegebundene Einladung. Datei/Desktop/Skript separat abnehmen.

## Sicherung und Rückweg

Vor Folgeveränderungen private Sicherung von Compose/Manifest/Config sowie
`native/data`, `native/files`, `native/backups` und Connector-State/Secrets;
NeDB-Sicherung bei sauber gestopptem nativen Dienst oder über dessen geprüften
Backupweg. Daten enthalten private Server-/Agentidentitäten: verschlüsselt,
restriktive Rechte, niemals Git/öffentliche Reports. Wiederherstellung in einem
separaten Pfad und an isolierten Ports prüfen; kein zweiter Server mit derselben
Identität parallel öffentlich betreiben.

Abbruch: nur die neu angelegten Compose-Dienste stoppen, eine später separat
angelegte öffentliche Route deaktivieren. Keine Volumes löschen, kein `down -v`,
keine globalen Docker-Prunes; Daten/Secrets für Diagnose erhalten. Vorhandene
Plesk-, Mail-, Windows- oder Microsoft-Konfigurationen bleiben unverändert.

## Lokale Tests

```bash
python3 -B -m unittest discover -s deploy -p test_bootstrap.py -v
```

Tests verwenden ausschließlich temporäre synthetische Dateien und simulierte
Port-/Linux-Eigentümerprüfungen; keine echten Secrets, Dienste oder Accounts.
Der eigentliche Linux-Dateirechte-/Docker-Smoke bleibt offen.

Quellen: [MeshCentral1.2.5](https://github.com/Ylianst/MeshCentral/releases/tag/1.2.5),
[Konfigurationsschema](https://raw.githubusercontent.com/Ylianst/MeshCentral/1.2.5/meshcentral-config-schema.json),
[Startabhängigkeiten](https://raw.githubusercontent.com/Ylianst/MeshCentral/1.2.5/meshcentral.js),
[bestehender Connector](../README.md).
