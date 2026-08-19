# RailTime - Software-Deployment & Geräte-Provisionierung

**Planungsstand:** 19. August 2026  
**Status:** Technische Planungsgrundlage, noch keine verbindliche Implementierungsfreigabe  
**Zielpfad im Repository:** `.dev/railtime-software-deployment-plan/`

## 1. Ziel und Abgrenzung

RailTime soll zentral steuern können, welche Software auf verwalteten Firmengeräten installiert sein soll. Dieselbe Steuerung muss sowohl bei der Ersteinrichtung eines Geräts als auch später für Nachinstallationen, Updates und Deinstallationen funktionieren.

Die RailTime-Anwendung bleibt dabei die fachliche Control Plane. Sie verwaltet den Sollzustand, Berechtigungen, Freigaben, Audit und Deployment-Aufträge. Die eigentliche technische Installation wird über capability-basierte Geräteprovider bzw. den jeweiligen Device-Agent ausgeführt.

Nicht Ziel dieses Moduls ist es, die vorhandene Geräteverwaltung neu zu bauen. Die bestehende RailTime-Gerätearchitektur wird erweitert.

## 2. Bereits vorhandene RailTime-Basis

Im aktuellen Repository `lucasz1991/App` existieren bereits die wesentlichen technischen Grundlagen:

- `App\Models\Device` für den Gerätebestand.
- `App\Models\DeviceCommand` für protokollierte Geräteaktionen mit Status, Korrelation, verschlüsseltem Payload und Ergebnis.
- `App\Enums\DeviceCommandType` enthält bereits `install_software`, `uninstall_software` und `apply_profile`.
- `App\Services\DeviceManagement\DeviceCommandService` übernimmt Berechtigung, Providerprüfung, Capability-Prüfung, Audit und Queueing.
- `App\Models\DeviceProvisioningProfile` enthält versionierbare Sollprofile für mehrere Plattformen.
- `App\Models\DeviceArtifact` unterstützt private Installationsartefakte inklusive SHA-256 und Freigabe.
- `App\Livewire\Devices\DeviceManagement` ist die bestehende zentrale Verwaltungsoberfläche.
- Geräteaufträge laufen bereits über die `devices`-Queue.
- `DeviceProviderRegistry` kapselt die Provider und deren Fähigkeiten.

Damit muss für die Softwareverwaltung keine zweite Remote-Command-Architektur entstehen. Ergänzt werden muss vor allem die verwaltbare Soll-/Ist-Schicht über den bestehenden Commands.

## 3. Zielarchitektur

```text
RailTime Admin
    |
    +-- Geräte & Lager
    |     +-- Geräte
    |     +-- Software-Katalog
    |     +-- Software-Profile
    |     +-- Deployments
    |     +-- Provider / Geräte-Setup
    |
    +-- Sollzustand je Gerät
            |
            +-- DeviceCommand
                    |
                    +-- DeviceProviderRegistry
                            |
                            +-- OpenUEM / Connector / Device-Agent / MDM
                                    |
                                    +-- Gerät
                                            |
                                            +-- Ist-Zustand zurück an RailTime
```

Grundprinzip: Die Benutzeroberfläche formuliert keinen beliebigen Shell-Befehl. Sie erzeugt einen fachlichen Softwareauftrag, der in einen vorhandenen, validierten `DeviceCommand` übersetzt wird.

## 4. Kernanwendungsfälle

### 4.1 Ersteinrichtung eines neuen Geräts

1. Gerät wird im virtuellen Lager erfasst.
2. Gerät wird einem Mitarbeiter bzw. einer Gerätegruppe zugeordnet.
3. Enrollment wird gestartet.
4. Ein Einrichtungs-/Softwareprofil wird ausgewählt.
5. Nach erfolgreicher Registrierung meldet der Provider bzw. Agent den Ist-Zustand.
6. RailTime vergleicht Soll und Ist.
7. Fehlende Pflichtsoftware wird als Deployment geplant.
8. RailTime erzeugt `install_software`-Commands.
9. Provider/Agent führt die Installation aus.
10. Ergebnisse und installierte Versionen werden zurückgemeldet.
11. Readiness wird erst positiv, wenn die belegbaren Pflichtzustände erfüllt sind.

Beispielprofil `Standard Arbeitsplatz`:

- Google Chrome
- Microsoft 365
- Outlook
- Teams
- OneDrive
- Adobe Acrobat Reader
- 7-Zip
- RailTime-Komponenten

### 4.2 Spätere Nachinstallation

Ein Administrator öffnet ein Gerät, wechselt zu `Software`, wählt ein Paket und startet die Installation. RailTime erzeugt denselben Deployment-Typ wie bei der Ersteinrichtung. Das Gerät muss nicht online sein; der Auftrag bleibt nachvollziehbar offen und wird ausgeführt, sobald der Provider/Agent das Gerät wieder erreicht.

### 4.3 Gruppen- und Profildeployment

Software soll nicht nur pro Einzelgerät zugewiesen werden können, sondern auch an:

- mehrere ausgewählte Geräte,
- eine Gerätegruppe,
- ein Software-/Provisioning-Profil,
- alle Geräte einer Plattform,
- später optional organisatorische Einheiten oder Rollen.

Ein Massenauftrag erzeugt pro Gerät einen eigenen nachvollziehbaren Deployment-Eintrag bzw. `DeviceCommand`, sodass Teilfehler sichtbar bleiben.

### 4.4 Deinstallation und Rücknahme

Software kann den Sollzustand `absent` erhalten. RailTime erzeugt dann einen `uninstall_software`-Command, sofern Provider und Paket diese Aktion unterstützen. Kritische oder systemrelevante Pakete können gegen Deinstallation geschützt werden.

## 5. Software-Katalog

Der Software-Katalog ist die zentrale fachliche Definition installierbarer Software. Er darf nicht mit einem konkreten Gerät vermischt werden.

Empfohlene Felder:

| Feld | Zweck |
|---|---|
| `name` | Anzeigename |
| `slug` | stabiler interner Schlüssel |
| `platform` | Windows, macOS, Android, iOS/iPadOS, Linux |
| `source_type` | winget, MSI, EXE, PKG, DMG, APK, Store, MDM, Script |
| `package_identifier` | Provider-/Paketmanager-ID |
| `current_version` | gewünschte oder bekannte Version |
| `artifact_id` | optionaler Bezug auf `DeviceArtifact` |
| `install_arguments` | freigegebene Silent-Parameter |
| `uninstall_arguments` | freigegebene Deinstallationsparameter |
| `detection_method` | Methode zur Ist-Erkennung |
| `detection_value` | Paket-ID, Bundle-ID, Produktcode o. Ä. |
| `requires_admin` | administrative Installation erforderlich |
| `requires_reboot` | Neustart kann erforderlich sein |
| `is_active` | Paket aktiv/verwendbar |

### 5.1 Bevorzugte Quellen

Für Standardsoftware soll nach Möglichkeit eine stabile, providerfähige Paketquelle verwendet werden. Für Windows ist `winget` sinnvoll, wenn Paket-ID und Silent-Installation zuverlässig sind. Eigene oder nicht öffentliche Software wird als freigegebenes RailTime-Artefakt verwaltet.

Beispiele:

```text
Google Chrome
Quelle: winget
Package-ID: Google.Chrome
Version: latest
```

```text
RailTime Connector
Quelle: MSI / DeviceArtifact
Datei: railtime-connector-1.4.2.msi
SHA-256: verpflichtend
Silent-Parameter: /qn
```

## 6. Software-Profile und Sollzustand

Ein Softwareprofil beschreibt, welche Pakete für einen Gerätetyp oder Einsatzzweck gelten.

Beispiele:

- `Standard Arbeitsplatz`
- `Verwaltung`
- `Disposition`
- `Geschäftsführung`
- `IT / Entwicklung`
- `Schulungsgerät`

Je Profileintrag sollten mindestens folgende Eigenschaften möglich sein:

- `required`: Pflichtsoftware oder optional.
- `desired_state`: `present` oder `absent`.
- `auto_update`: RailTime darf auf freigegebene Zielversion aktualisieren.
- `install_order`: Reihenfolge bei Abhängigkeiten.
- `allow_user_uninstall`: fachliche Regel, soweit Provider dies technisch erzwingen kann.
- `minimum_version` bzw. `target_version`.

Der bereits vorhandene `DeviceProvisioningProfile` kann für den Profilrahmen genutzt werden. Sinnvoll ist ein eigener Profiltyp wie `software_bundle`, während die Paketbeziehungen normalisiert in einer eigenen Tabelle liegen.

## 7. Soll-/Ist-Modell

RailTime benötigt zwei getrennte Sichten:

**Soll:** Was soll auf diesem Gerät vorhanden sein?  
**Ist:** Was wurde tatsächlich erkannt bzw. vom Provider bestätigt?

Beispiel:

| Software | Soll | Ist | Bewertung |
|---|---|---|---|
| Chrome | vorhanden, aktuelle Freigabe | 140.x | OK |
| Microsoft 365 | vorhanden | installiert | OK |
| Adobe Reader | vorhanden | nicht erkannt | Deployment nötig |
| VLC | optional | nicht installiert | OK |
| Altes VPN | nicht vorhanden | installiert | Deinstallation nötig |

Diese Trennung verhindert, dass ein erfolgreicher "Auftrag gesendet"-Status fälschlich als "Software installiert" interpretiert wird.

## 8. Empfohlenes Datenmodell

### 8.1 `device_software_packages`

Katalog der verwaltbaren Softwarepakete.

Wesentliche Felder:

- `id`, `public_id`
- `name`, `slug`
- `platform`
- `source_type`
- `package_identifier`
- `current_version`
- `device_artifact_id`
- `install_arguments`, `uninstall_arguments`
- `detection_method`, `detection_value`
- `requires_admin`, `requires_reboot`
- `is_active`
- `created_by`, `updated_by`

### 8.2 `device_software_profile_items`

Verknüpft `DeviceProvisioningProfile` mit Softwarepaketen.

- `device_provisioning_profile_id`
- `software_package_id`
- `desired_state`
- `required`
- `auto_update`
- `install_order`
- `minimum_version`
- `target_version`

### 8.3 `device_software_assignments`

Expliziter Sollzustand auf Geräteebene. Damit können Profilwerte überschrieben oder einzelne Programme zusätzlich zugewiesen werden.

- `device_id`
- `software_package_id`
- `desired_state`
- `source` (`profile`, `manual`, `group`, später weitere)
- `source_reference`
- `assigned_by`
- `assigned_at`
- `revoked_at`

### 8.4 `device_installed_software`

Belegbarer Ist-Zustand.

- `device_id`
- `software_package_id` optional
- `detected_name`
- `detected_identifier`
- `detected_version`
- `install_status`
- `provider`
- `first_seen_at`
- `last_seen_at`
- `raw_reference` bzw. providerbezogene Referenz, soweit datenschutzkonform

### 8.5 Optional: `device_software_deployments`

Ein fachlicher Deployment-Kopf ist sinnvoll, wenn Massenaktionen, Fortschritt und Wiederholungen übersichtlich dargestellt werden sollen. Die technische Ausführung bleibt pro Gerät über `DeviceCommand` nachvollziehbar.

## 9. Deployment-Lifecycle

Empfohlener fachlicher Ablauf:

```text
planned
  -> queued
  -> dispatched
  -> running
  -> verifying
  -> completed

Fehlerpfad:
  -> failed
  -> retry_planned
  -> queued
```

Der vorhandene `DeviceCommand` bleibt die technische Ausführungseinheit. Ein Software-Deployment speichert zusätzlich den fachlichen Bezug auf Softwarepaket, Zielversion und Sollquelle.

Nach dem technischen Command sollte möglichst eine Verifikation erfolgen. `completed` bedeutet bei Software nicht nur, dass der Installer ohne Transportfehler gestartet wurde, sondern dass Provider/Agent den Zielzustand anschließend bestätigen konnte.

## 10. Command-Integration

Vorhandene Commands:

- `install_software`
- `uninstall_software`
- `apply_profile`

Beispielpayload für ein providerfähiges Paket:

```json
{
  "software_package_id": 18,
  "package_identifier": "VideoLAN.VLC",
  "desired_version": "latest"
}
```

Beispielpayload für ein freigegebenes Artefakt:

```json
{
  "software_package_id": 44,
  "artifact_id": 912,
  "desired_version": "1.4.2"
}
```

Wichtig: Der Command soll keine Passwörter, Tokens oder sonstige Geheimnisse transportieren. Diese Sicherheitsgrenze entspricht bereits dem vorhandenen `DeviceCommandService`.

## 11. Provider- und Agent-Verantwortung

RailTime selbst soll keine plattformspezifische Installationslogik in Livewire oder Controllern ausführen.

Der jeweilige Provider/Connector übernimmt:

- Auflösung des fachlichen Pakets in eine technische Installation.
- Download bzw. Paketmanager-Aufruf.
- Silent-Installation.
- Prüfung von Exit Codes.
- Reboot-Anforderung.
- Erkennung der installierten Version.
- Rückmeldung eines standardisierten Ergebnisses.

RailTime prüft vor Erstellung des Commands über die vorhandene Capability-Logik, ob der gewählte Provider den Befehl für die Plattform unterstützt.

Für einen eigenen Windows-Agenten ist ein Windows Service einer reinen Benutzer-App vorzuziehen, da Installationen unabhängig von angemeldetem Benutzer, UI und Neustarts laufen müssen.

## 12. UI-Konzept

### 12.1 Navigation unter `Geräte & Lager`

```text
Geräte & Lager
  - Geräte
  - Software
  - Software-Profile
  - Deployments
  - Geräte-Setup / Provider
```

### 12.2 Gerätedetail

Empfohlene Tabs:

```text
Übersicht | Benutzer | Einrichtung | Software | Aktionen | Verlauf
```

Der Tab `Software` zeigt:

- Sollzustand.
- Istzustand.
- erkannte Version.
- Quelle der Zuweisung.
- laufendes Deployment.
- letzten Fehler.
- Aktionen `Installieren`, `Neu installieren`, `Aktualisieren`, `Deinstallieren`, sofern erlaubt.

### 12.3 Software-Katalog

Karten- oder Tabellenansicht mit:

- Name und Icon.
- Plattform.
- Quelle.
- Zielversion.
- Anzahl Geräte mit Sollzuweisung.
- Anzahl Geräte konform / abweichend / unbekannt.
- Aktivstatus.

### 12.4 Einrichtungsdialog

Beim Enrollment bzw. bei der Geräteübergabe kann ein Softwareprofil ausgewählt werden. Für Standardgeräte sollte eine sinnvolle Voreinstellung existieren, die vor dem Deployment noch sichtbar bestätigt werden kann.

## 13. Berechtigungen und Audit

Empfohlene zusätzliche Berechtigungen:

- `devices.software.view`
- `devices.software.manage`
- `devices.software.deploy`
- `devices.software.uninstall`
- `devices.software.profiles.manage`

Jede Änderung an Paketdefinition, Profil, Gerätezuweisung und Deployment wird im bestehenden `device-management`-Audit protokolliert.

Mindestens zu speichern:

- wer die Aktion ausgelöst hat,
- auf welches Gerät / welche Gruppe,
- welches Paket und welche Zielversion,
- Quelle der Sollzuweisung,
- verwendeter Provider,
- `DeviceCommand`-Korrelations-ID,
- Ergebnis und Fehlerstatus.

## 14. Sicherheitsregeln

1. Keine frei editierbaren Shell-Befehle als normale Softwarepakete.
2. Eigene Installer nur als freigegebene `DeviceArtifact`-Version mit SHA-256.
3. Silent-Parameter werden im Katalog administrativ freigegeben.
4. Keine Credentials oder Tokens in `DeviceCommand.payload`.
5. Provider-Capabilities bleiben bindend; UI zeigt keine nicht belegte Funktion als verfügbar.
6. Der bestehende globale Kill-Switch für externe Gerätebefehle bleibt wirksam.
7. Deinstallation besonders kritischer Software kann separat geschützt werden.
8. Software-Readiness basiert auf nachgewiesenem Ist-Zustand, nicht auf "Command versendet".
9. Massenaktionen müssen vor Ausführung Zielanzahl und betroffene Geräte transparent anzeigen.
10. Rollout neuer Pakete soll optional zunächst auf Test-/Pilotgeräte beschränkt werden können.

## 15. Umsetzung in Phasen

### Phase 1 - Fachliches Modell und Katalog

- Migrationen und Models für Softwarepakete, Profileinträge, Geräte-Sollzustand und Istzustand.
- Policies/Gates.
- Software-Katalog im Admin.
- Verknüpfung mit `DeviceArtifact`.
- Noch keine automatische Installation notwendig.

### Phase 2 - Einzelgerät-Deployment

- `Software`-Tab im bestehenden `DeviceManagement`.
- Install-/Uninstall-Auftrag über `DeviceCommandService`.
- Statusanzeige über bestehende Command-States.
- Provider-Capability-Prüfung.
- Audit.

### Phase 3 - Erst-Provisionierung

- Auswahl eines Softwareprofils bei Enrollment/Übergabe.
- Sollzustand automatisch erzeugen.
- Reconciliation-Service vergleicht Soll/Ist.
- Fehlende Pflichtsoftware wird geplant.
- Readiness erhält Software-Checks.

### Phase 4 - Gruppen- und Massenrollout

- Mehrfachauswahl / Gerätegruppen.
- Deployment-Kopf mit Fortschrittsübersicht.
- gestufte Wellen, z. B. Pilot -> 25 % -> 100 %.
- Retry- und Fehlerauswertung.

### Phase 5 - Versionen und Compliance

- Ziel-/Mindestversionen.
- Update-Strategien.
- automatische Abweichungserkennung.
- optional geplante Wartungsfenster.
- Compliance-Dashboard.

## 16. Empfohlene Services / Klassen

Mögliche neue Komponenten innerhalb `App\Services\DeviceManagement`:

- `DeviceSoftwareCatalogService`
- `DeviceSoftwareAssignmentService`
- `DeviceSoftwareReconciliationService`
- `DeviceSoftwareDeploymentService`
- `DeviceSoftwareInventoryService`

Die Services verwenden vorhandene Komponenten statt sie zu umgehen:

- `DeviceCommandService`
- `DeviceProviderRegistry`
- `DeviceArtifactService`
- `DeviceReadinessService`
- `DeviceProvisioningProfileCatalog`

## 17. Offene Planungsentscheidungen

Vor der Implementierung sollten folgende Punkte festgelegt werden:

1. Soll `DeviceProvisioningProfile` direkt als allgemeines Geräteprofil weiterentwickelt werden oder bleiben Konto-/SSO-Profile und Software-Bundles fachlich getrennte Profiltypen?
2. Welche Provider liefern tatsächlich ein vollständiges Installed-Software-Inventar pro Plattform?
3. Wird für Windows zunächst OpenUEM verwendet oder zusätzlich/eigenständig ein RailTime-Agent vorgesehen?
4. Welche Standardsoftware gehört verbindlich in das erste `Standard Arbeitsplatz`-Profil?
5. Welche Pakete dürfen Mitarbeiter optional selbst anfordern?
6. Wie werden lizenzpflichtige Anwendungen und verfügbare Lizenzplätze modelliert?
7. Welche Updatepolitik gilt: `latest`, freigegebene Version oder Mindestversion?
8. Welche Software darf niemals automatisch deinstalliert werden?
9. Welche Wartungsfenster sind für Neustarts und größere Installationen zulässig?
10. Wie genau fließt Software-Compliance in den bestehenden Readiness-Status ein?

## 18. Nächster Planungsschritt

Als nächstes sollte kein Provider-Code geschrieben werden. Zuerst wird das fachliche Datenmodell und die UI-Struktur finalisiert. Anschließend kann an einem einzelnen Windows-Testgerät ein vertikaler Durchstich gebaut werden:

`Software-Katalog -> Gerätezuweisung -> DeviceCommand install_software -> Provider/Agent -> Ist-Erkennung -> RailTime-Status`.

Wenn dieser Durchstich zuverlässig funktioniert, wird derselbe Mechanismus auf Erst-Provisionierung und Massenrollouts erweitert.

## 19. Relevante bestehende RailTime-Dateien

- `app/Models/Device.php`
- `app/Models/DeviceCommand.php`
- `app/Models/DeviceProvisioningProfile.php`
- `app/Models/DeviceArtifact.php`
- `app/Enums/DeviceCommandType.php`
- `app/Services/DeviceManagement/DeviceCommandService.php`
- `app/Services/DeviceManagement/DeviceProviderRegistry.php`
- `app/Services/DeviceManagement/DeviceProvisioningProfileCatalog.php`
- `app/Services/DeviceManagement/DeviceArtifactService.php`
- `app/Services/DeviceManagement/DeviceReadinessService.php`
- `app/Livewire/Devices/DeviceManagement.php`
- `.dev/railtime-device-management-research/`

---

Diese Datei dient als fortschreibbare Planungsgrundlage. Änderungen an Architekturentscheidungen sollten zuerst hier dokumentiert werden, bevor daraus Migrationen, Services oder Provider-Verträge abgeleitet werden.
