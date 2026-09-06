# Microsoft Entra und Windows-Geräte in RailTime

Stand: 6. September 2026. Der native Graph-Abruf ist implementiert und lokal
mit isolierten HTTP-/Datenbanktests geprüft. Ein echter Mandantenabruf ist
erst nach Eintragung der Zugangsdaten möglich.

## Was automatisch passiert

1. Ein Windows-Gerät wird bei Microsoft Entra registriert, Entra joined oder
   hybrid joined. Eine reine Anmeldung bei einer Office-App muss keinen
   Geräteeintrag erzeugen.
2. RailTime liest standardmäßig alle 15 Minuten die Geräte des konfigurierten
   Mandanten. Es übernimmt Windows-Geräte einschließlich Entra-IDs, OS-Version,
   Registrierungsart und der verfügbaren Inventardaten.
3. Bei aktivierter Intune-Ergänzung werden Seriennummer, Modell,
   Compliance-Meldung, letzter Intune-Kontakt und Hauptbenutzer gelesen.
4. Eine eindeutige Microsoft-Benutzer-ID wird ausschließlich über eine
   bestehende Bindung `Tenant-ID + Objekt-ID + RailTime-Mitarbeiter` zugeordnet.
   Eine gleiche E-Mail-Adresse allein reicht nicht.
5. Neue, noch nicht zugeteilte Geräte können automatisch zugeordnet werden.
   Intune-Hauptbenutzer hat Vorrang vor dem Entra-Registrierungsbesitzer.
   Bereits bestehende andere Zuteilungen, Rückgaben oder Bereitstellungsaufträge
   führen zur manuellen Prüfung. Eine automatische Zuordnung bestätigt keine
   physische Übergabe und keine vollständige Betriebsbereitschaft.

Der vorhandene verifizierte Microsoft-Bootstrap im RailTime-Outlook-Add-in
stößt zusätzlich einen fälligen Abgleich an. Wiederholte Bootstrap-/304-Aufrufe
werden gedrosselt. Ein Windows-Login ruft RailTime nicht direkt auf; dafür
erfasst der periodische Abgleich neue Entra-Einträge. Ein zusätzlicher
Microsoft-Weblogin für die RailTime-Anmeldeseite wurde nicht eingeführt.

## Einrichtung in Microsoft und RailTime

1. In Microsoft Entra eine Anwendung für **diesen einen Mandanten** verwenden
   oder registrieren. Tenant-ID, Anwendungs-/Client-ID und ein gültiges
   Client-Geheimnis werden benötigt. Das vorhandene Outlook-Add-in-Token ist
   für die RailTime-API bestimmt und wird nicht als Graph-Token verwendet.
2. Microsoft Graph **Application permission `Device.Read.All`** erteilen und
   die Administratorzustimmung bestätigen. Für die optionale Intune-Abfrage
   zusätzlich **`DeviceManagementManagedDevices.Read.All`**; dafür muss eine
   aktive Intune-Lizenz vorhanden sein. Es werden keine Schreibrechte,
   `Directory.ReadWrite.All`, Mail- oder Sign-in-Log-Rechte benötigt.
3. Nach Deployment die Migrationen mit `php artisan migrate --force`
   ausführen. Die neue Migration ist additiv, erhält bestehende Geräte und
   lässt bisher unbekannte Tenant-Zuordnungen zunächst leer.
4. Unter **Einstellungen → Geräte-Setup → Microsoft Entra & Windows** die
   IDs und das Geheimnis eintragen, speichern und **Verbindung testen**.
   Erst danach die automatische Synchronisierung aktivieren. Die Werte
   liegen in der Datenbank, das Geheimnis verschlüsselt; keine neuen ENV-Werte.
   Für Graph werden die festen Microsoft-HTTPS-Endpunkte verwendet. Die
   bisherigen lokalen Plesk-Subdomains/Ports der anderen Connectoren bleiben
   unabhängig davon.
5. Unter **Geräte → Microsoft-Konten** vorhandene Mitarbeiter mit ihrer
   Microsoft-Benutzer-Objekt-ID und ihrem UPN verbinden. Die Entra-Objekt-ID
   des Benutzers ist nicht die Client-ID der App oder die Geräte-ID.
   Eine bereits etablierte identische Kontobindung kann ihren bislang leeren
   Tenant ausdrücklich bestätigen; vorhandene andere Bindungen werden nicht
   überschrieben. Bei passender bereits verifizierter Outlook-Anmeldung kann
   der bisher leere Tenant auch über diesen authentifizierten Weg ergänzt werden.
6. Den dedizierten Hintergrund-Worker starten und den Laravel-Scheduler
   regelmäßig ausführen. Anschließend **Jetzt synchronisieren** verwenden.

```bash
php artisan queue:work microsoft_devices --queue=microsoft-devices --timeout=240 --tries=1
php artisan schedule:run
php artisan devices:sync-microsoft --force
```

Der erste Befehl gehört in einen dauerhaft betreuten Worker-Prozess; der zweite
in den bestehenden minutengenauen Plesk-Cron. Die neue Connection
`microsoft_devices` verwendet die vorhandene Standarddatenbank und `jobs` mit
`retry_after=300`. Sie funktioniert auch, wenn die allgemeine App-Queue auf
`sync` steht. Es sind keine neuen Queue-ENV-Werte erforderlich. Bei einem
vorhandenen Konfigurationscache muss dieser mit dem Deployment erneuert werden.
Der manuelle CLI-Befehl stellt ebenfalls nur einen Hintergrundauftrag ein.

## Oberfläche und Konflikte

- Geräteübersicht: Microsoft-Abgleich-Button, Kontozuordnungsmodal sowie
  Filter für Entra, Intune und zu prüfende Microsoft-Zuordnungen.
- Gerätedetail: Registrierungsart, Intune-Nachweis, erkannter Mitarbeiter,
  Quelle der Zuordnung, Kontaktdatum und getrennte Entra-/Intune-IDs.
- Ein bestehendes Inventargerät wird anhand einer eindeutigen Intune-
  Seriennummer wiedererkannt. Entra allein liefert keine verlässliche
  Seriennummer. Gleiche Gerätenamen werden nicht automatisch zusammengeführt.
- Seriennummern werden nachträglich ergänzt, sofern sie leer und eindeutig
  sind. Widersprüche erscheinen als Klärungsfall; lokale Kennungen werden
  nicht überschrieben.
- Fehlende oder mehrdeutige Besitzer, unbekannte Mitarbeiter, deaktivierte
  Entra-Geräte und widersprüchliche Zuteilungen bleiben sichtbar.
- Ein verschwundener Entra-Eintrag löscht kein RailTime-Gerät und verändert
  keine ausgegebene Hardware oder Zuteilung.

## Grenzen der Daten

Der Entra-Registrierungsbesitzer kann der IT-Mitarbeiter sein, der das Gerät
eingerichtet hat. Bei geteilten oder Hybridgeräten kann der Besitzer fehlen.
Intune-Hauptbenutzer ist deshalb, soweit vorhanden, die bevorzugte Quelle.
Fällt die konfigurierte Intune-Abfrage aus, erfolgt keine automatische
Zuordnung anhand eines vermeintlichen Ersatzbesitzers.

Entra-Registrierung ist keine MDM-Einschreibung. Graph-Inventardaten setzen
in RailTime weder Enrollment-/MFA-/Lizenz-/App-Profile auf erfolgreich noch
Fernsupport auf erreichbar. Der ungefähre Entra-Anmeldezeitpunkt ist kein
Live-Heartbeat. Die bestehende MeshCentral-/UEM-Verwaltung kann parallel
genutzt werden; deren Provider-IDs und Freigaben bleiben eigenständig.

## Technische Umsetzung und Prüfung

- `MicrosoftGraphDeviceClient`: v1.0, Client Credentials, vollständige
  Folgeseiten, GET-Teilabfragen in 20er-Batches, feste Hosts und Ressourcenpfade,
  keine Redirects oder Weitergabe von Tokens an Paging-Fremdziele.
- `MicrosoftDeviceSyncService`: Konfiguration und Fingerprint aus einem
  Snapshot; Konfigurationsprüfung vor dem transaktionalen Import, stabile
  Entra-/Intune-Identitäten, gesperrte Konten/Zuweisungen, keine Teilimporte bei
  einem fehlerhaften Entra-/Besitzerabruf.
- `MicrosoftDeviceSyncScheduler` / `SyncMicrosoftDevices`: Queue-/Intervall-
  Deduplizierung, begrenzte Sperren, erneute Konfigurationsprüfung, keine
  Passwörter oder Microsoft-Zugriffstokens in Jobs.
- `MicrosoftEmployeeLinkService`: `devices.accounts.manage`, explizite
  administrative Kontobindung und Audit. Konfiguration benötigt weiterhin
  `settings.manage` und den RailTime-Superadmin.

Die lokalen Regressionen stehen in `MicrosoftDeviceSyncTest`,
`MicrosoftDeviceSyncTriggerTest`, `MicrosoftDeviceSettingsTest` und
`MicrosoftEmployeeLinkTest`. Echte Microsoft-Zugriffe sind in diesen Tests
gesperrt und werden durch definierte Graph-Antworten ersetzt.

## Offizielle Verträge

- [Entra-Geräte lesen und minimale Rechte](https://learn.microsoft.com/en-us/graph/api/device-list?view=graph-rest-1.0)
- [Registrierungsbesitzer und deren Bedeutung](https://learn.microsoft.com/en-us/graph/api/device-list-registeredowners?view=graph-rest-1.0)
- [Intune-Geräte lesen und Lizenzvoraussetzung](https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0)
- [Intune-Gerätefelder und Hauptbenutzerbeziehung](https://learn.microsoft.com/en-us/graph/api/resources/intune-devices-manageddevice?view=graph-rest-1.0)
- [Intune-Hauptbenutzer und Entra-Besitzer](https://learn.microsoft.com/en-us/intune/device-management/inventory-and-status/find-primary-user)
- [Graph-Batches](https://learn.microsoft.com/en-us/graph/json-batching)
- [Beschränkungen von Directory-Expand](https://learn.microsoft.com/en-us/graph/known-issues#some-limitations-apply-to-query-parameters)
- [Geschäftskonto auf einem Windows-Gerät](https://support.microsoft.com/en-us/windows/security/identity-signin/add-your-work-or-school-account-to-a-windows-device)
