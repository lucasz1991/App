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

## Plesk-Referenzen

- [Docker-Ports an Loopback binden und per Proxy Rule veröffentlichen](https://docs.plesk.com/en-US/obsidian/administrator-guide/plesk-administration/using-docker.75823/)
- [nginx in Plesk an einen lokalen Anwendungsport weiterleiten](https://support.plesk.com/hc/en-us/articles/12388464421143-How-to-pass-requests-from-a-Plesk-hosted-domain-to-the-application-listening-on-a-local-port)
- [Subdomains mit SSL It! absichern](https://docs.plesk.com/en-US/obsidian/customer-guide/websites-and-domains/securing-connections-with-ssltls-certificates/securing-connections-with-the-ssl-it%21-extension.65160/)
