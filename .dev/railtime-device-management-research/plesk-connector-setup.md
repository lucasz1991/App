# Plesk-Connectorbetrieb ohne `DEVICE_*`-Variablen

RailTime verwaltet seine Geräteconnectoren unter **Einstellungen →
Geräte-Setup**. Die Laufzeitwerte liegen verschlüsselt in der vorhandenen
`settings`-Tabelle; Zugangstoken und Webhook-Secrets werden nach dem Speichern
nur noch maskiert angezeigt.

## Zwei unterstützte Betriebsarten

### HTTPS-Subdomain

Dies ist die empfohlene Variante, wenn ein Connector oder seine Enrollment-
Seite von Geräten beziehungsweise Browsern erreicht werden muss. Plesk/nginx
nimmt den Verkehr auf Port 443 entgegen und leitet ihn intern an den jeweiligen
Container oder Prozess weiter. Die Oberfläche schlägt pro Provider ein
eindeutiges Subdomainpräfix vor und bildet daraus die effektive URL.

### Privater RailTime-Adapterport

Wenn ausschließlich RailTime selbst den Connector erreichen muss, verbindet
sich die Anwendung mit `http://127.0.0.1:<port>`. Die vorbelegten Ports sind
reservierte RailTime-Adapterports und ausdrücklich keine dokumentierten
Herstellerports von OpenUEM, MeshCentral, Headwind oder NanoMDM. Sie sollen an
Loopback gebunden und nicht in der öffentlichen Firewall freigeschaltet werden.

## Was RailTime automatisch prüft

Der Funktionstest validiert die gespeicherte, serverseitig berechnete Adresse,
die vorhandene Authentifizierung und die Antwort des versionierten
`GET /v1/health`-Endpunkts. Redirects sind deaktiviert, Zeit und Antwortgröße
sind begrenzt und das angezeigte Ergebnis wird von Geheimnissen bereinigt.
Enrollment, Skripte, Lock oder Wipe sind niemals Bestandteil dieses Tests.

## Was weiterhin in Plesk eingerichtet werden muss

- Container oder Prozesse der ausgewählten Open-Source-Dienste,
- je Dienst der RailTime-Connector aus dem dokumentierten Vertrag,
- Reverse-Proxy-Regeln und TLS-Zertifikate für öffentliche Subdomains,
- persistente Volumes, Backups, Monitoring und Queue-Worker,
- die eigenen Konten, Schlüssel und Hersteller-/Plattformanbindungen der
  jeweiligen Dienste.

RailTime legt diese Infrastruktur nicht stillschweigend an und scannt auch
keine unbekannten Ports. Die Standardwerte sind sichere Vorschläge; ein grüner
Health-Test belegt Erreichbarkeit und Vertragskompatibilität, nicht automatisch
die vollständige Produktionsreife aller Geräteaktionen.

## Umgesetzter MeshCentral-Connector

Der erste separat deploybare Adapter liegt unter
[`services/device-connectors/meshcentral`](../../services/device-connectors/meshcentral/README.md).
Er implementiert den Vertrag `1.0.0` auf dem vorbelegten privaten Port `9442`
und pinnt die offiziell ausgelieferte `meshctrl.js` auf MeshCentral `1.2.5`.
Seine Laufzeit benötigt keine Environment-Variablen:

- Betriebswerte kommen aus einer JSON-Datei,
- Bearer-Token, RailTime-HMAC-Secret und MeshCentral-Loginkey kommen aus
  einzelnen geschützten Dateien,
- RailTime verwaltet die korrespondierenden Connectorwerte weiterhin
  verschlüsselt unter **Einstellungen → Geräte-Setup**,
- RailTime und Connector müssen jeweils denselben Bearer- und HMAC-Wert
  besitzen; RailTime überträgt diese Werte nicht automatisch in das Dateisystem
  des Plesk-Hosts.

Die offizielle `meshctrl.js` 1.2.5 deaktiviert die Zertifikatsprüfung für ihren
WebSocket. Der RailTime-Adapter kompensiert diese Upstream-Eigenschaft, indem er
für `meshcentral.url` ausschließlich `wss://127.0.0.1` beziehungsweise
`wss://[::1]` auf demselben Plesk-Host akzeptiert. Die von Geräten erreichbare
MeshCentral-Adresse bleibt eine getrennte, gültig zertifizierte
`https://support.<domain>`-URL. Für Docker ist daher Host-Network oder alternativ
ein direkt auf dem Plesk-Host gestarteter Node-22-systemd-Service vorgesehen.

Der genaue Docker-/systemd-Start, Mounts, Dateirechte, Secret-Erzeugung und die
RailTime-Zuordnung sind in der Connector-README dokumentiert. Der Connector
bietet ausschließlich belegte MeshCentral-Fähigkeiten an: DeviceInfo, Remote-
Support für eine bereits aktiv gebundene native Node-ID sowie geprüfte
PS1/BAT/CMD/SH-Artefakte über HMAC-Download, SHA-256, Upload und RunCommand mit
Erfolgsmarker. `POST /v1/enrollments` und `restart` werden fail-closed
abgelehnt. Freie Requestoptionen, Kennwörter, Lock, Wipe und vermeintliche
MDM-Funktionen sind ausgeschlossen.

Der MeshAgent wird separat über das freigegebene UEM/MDM oder administrativ
kontrolliert installiert. Danach wird die in MeshCentral geprüfte native
Node-ID als Support-Link mit dem bereits inventarisierten RailTime-Gerät
verbunden. Ein generischer Gruppeninvite gilt nicht als RailTime-Enrollment
und erzeugt keine `enrollment.completed`-Quittung.

## Plesk-Referenzen

- [Docker-Ports an Loopback binden und per Proxy Rule veröffentlichen](https://docs.plesk.com/en-US/obsidian/administrator-guide/plesk-administration/using-docker.75823/)
- [nginx in Plesk an einen lokalen Anwendungsport weiterleiten](https://support.plesk.com/hc/en-us/articles/12388464421143-How-to-pass-requests-from-a-Plesk-hosted-domain-to-the-application-listening-on-a-local-port)
- [Subdomains mit SSL It! absichern](https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/securing-connections-with-ssltls-certificates/securing-connections-with-the-ssl-it%21-extension.65160/)
- [MeshCentral 1.2.5 (fixierter Upstream)](https://github.com/Ylianst/MeshCentral/releases/tag/1.2.5)
