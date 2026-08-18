# Produktions-Testlauf

## Stufe A – lokal ohne externe Mutation

1. Migration nur in einer frischen Testdatenbank ausführen.
2. Als Benutzer #1 unter **Einstellungen → Geräte-Setup** kontrollieren, dass
   der globale Schalter für produktive Gerätebefehle ausgeschaltet ist.
3. Der deterministische Simulationsprovider steht ausschließlich in
   `local`/`testing` zur Verfügung und benötigt keine `DEVICE_*`-Variable.
4. Gerät anlegen, Mitarbeiter zuweisen, Microsoft-/Google-Identitätsreferenz
   erfassen, Enrollment-E-Mail erzeugen und Link einmalig einlösen.
5. Readiness zeigt ehrlich offene Provider-/Nutzeraktionen.
6. Berechtigungs-, Token-, Audit-, Queue- und Wipe-Vier-Augen-Tests ausführen.

   ```bash
   php artisan migrate
   php vendor/bin/phpunit tests/Feature/DeviceManagementTest.php
   npm run build
   ```

## Stufe B – Connector-Labor

Voraussetzungen:

- eigene TLS-Hosts und minimale Servicekonten,
- getrennte Connector-Tokens und HMAC-Webhook-Secrets,
- Queue-Worker für `devices`,
- ausgewiesene Laborgeräte,
- Backup/Restore und Monitoring.
- implementierter und gegen
  [`connector-contract.openapi.yaml`](connector-contract.openapi.yaml)
  validierter Connector.

Einrichtung in RailTime:

1. Unter **Einstellungen → Geräte-Setup** die bestätigte Basisdomain eintragen.
2. Pro Connector entweder eine HTTPS-Subdomain oder den privaten
   RailTime-Adapterport auf `127.0.0.1` wählen. Die vorbelegten Ports gehören
   zum RailTime-Connectorvertrag und sind keine nativen Tool-Ports.
3. Token und Webhook-Secret eintragen, speichern und anschließend
   **Verbindung prüfen** ausführen. Der Test darf ausschließlich
   `GET /v1/health` aufrufen.
4. Erst nach einem erfolgreichen, protokollierten Health-Test den Provider
   aktivieren. Der globale Mutationsschalter bleibt weiterhin aus.

Ablauf pro Provider:

1. Nur Health/Inventar aktivieren.
2. Enrollment an einem Laborgerät.
3. harmlose Synchronisation.
4. Datei/Paket/freigegebenes Skript, soweit Fähigkeit belegt.
5. Remote-Sitzung mit sichtbarer Nutzerzustimmung, soweit Plattform verlangt.
6. Lock, danach Unlock.
7. Wipe zuletzt, ausschließlich am entbehrlichen Laborgerät nach Vier-Augen-
   Freigabe.
8. Offline-, Timeout-, doppelter Webhook- und falsche-Signatur-Fälle prüfen.

## Stufe C – Pilot

- Zehn Mitarbeitende, schriftlich beschriebener Verwaltungsumfang.
- Keine erzwungenen Resets von Bestandsmobilgeräten.
- Support und Rollback während der Welle besetzt.
- Messgrößen: Abschlussquote, Zeit pro Plattform, Fehlerursache, offene
  Nutzeraktion, Providerlatenz, Supportfälle.

## Go-live-Gate

Der Mutationsschalter wird erst pro Provider freigegeben, wenn Connector,
Laborgerät, Audit, Rechte, Queue, Webhook, Restore und Zertifikatserneuerung
belegt sind. Android Full Management bleibt gesperrt, bis ein qualifizierter
EMM-/Lizenzweg entschieden und getestet wurde.

Die Gerätekonfiguration selbst liegt verschlüsselt in der RailTime-Datenbank;
`DEVICE_*`-Einträge in `.env` werden nicht benötigt. Das ersetzt nicht die
Laravel-Basiswerte wie `APP_KEY`/Datenbankzugang und nicht die jeweils eigene
Konfiguration der auf Plesk betriebenen Open-Source-Dienste.
