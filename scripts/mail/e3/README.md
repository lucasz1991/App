# C-K17: Stufe 2 - prüfbare Vorbereitung, keine Aktivierung

Stand 04.11.2026. Diese Fassung ergänzt den älteren
`PLAN-STUFE2-CONNECTOR.md` und das E-Ergebnis. Sie ersetzt insbesondere dessen
alten Graph-Kopierweg, globale Postfix-Skizzen und die unbewiesene Zusage einer
sofortigen Originalzustellung bei Dienstausfall.

**Aktueller lokaler Implementierungsstand:** Admission, Ticketspeicher,
Queue-/Pipe-Aufrufer und privater Rücklauf-Milter sind jetzt implementiert und
lokal testbar, weiterhin deaktiviert und nicht installiert. Maßgeblich ist
[LOCAL-READY.md](LOCAL-READY.md) mit konkretem Freigabevertrag, fehlenden echten
POSIX-/Postfix-/MIME-Belegen und dem weiterhin offenen Zertifikatsweg.
Die untenstehenden früheren Fehlstellen-/Reviewabschnitte bleiben als Verlauf
erhalten; sie stellen keine aktuelle Aktivierung dar.

**E3 ist noch nicht aktiviert.** Ausführbar sind die lesende
Exchange-Vorprüfung und - nach gesonderter Freigabe und erfüllten Prüfungen -
die Anlage ausschließlich deaktivierter Exchange-Objekte. Es gibt keinen
Enable-Befehl und keinen ausführbaren Postfix-Installer. Die notwendigen noch
fehlenden Betriebsbausteine werden unten ausdrücklich benannt.

## Verbindlicher Umfang

- Produktionsvorlage v2.1/C-K21 gilt, normalisierter SHA-256
  `942f8bf2e36712fb616d5acd3096d642baf3731c7952f72cadd1a474796600ba`.
  U2g/v3 wird nicht übernommen.
- B1-`send` ist die einzige Versandprojektion: vollständiger HEAD mit
  Viewport-Metas und den gepinnten Medienregeln; eingebettete Bilder als echte
  IMG/CID. Kein Hintergrundbild-Ersatz für den Zug.
- Absender ausschließlich `it-technik@rail-time.de`, authentifiziert intern,
  Envelope-Prüfung UND `X-RailTime-Template`. Zunächst auch Empfänger nur dieses
  IT-Postfach, keine externen Dritten. Der aus der aktuellen B1-Zulassung
  stammende exakte Header ist vor der späteren Konfiguration erneut zu prüfen.
- E1 ist entschieden: Die Editor-Kopie bleibt in Gesendete Elemente. Kein
  Graph-Schreiben, keine neuen Graph-Rechte, kein Ersetzen/Löschen dieser Kopie.
- Outlooks normaler Senden-Knopf und die sichtbare Editorfassung bleiben der
  Produktweg. C-K16 (iPhone-Verfassen) ist davon getrennt offen.

## Konkreter Transportvertrag

1. Eine neue deaktivierte Exchange-Regel prüft internen IT-Envelope und
   Versionsheader, begrenzt zunächst auf IT als Empfänger. Sie verwendet nur
   den regelgebundenen Outbound-Connector zu `plesk.follow-flow.de` mit TLS und
   Namensprüfung. Keine globale Umleitung, kein zusätzliches Senderecht.
2. Für einen echten Tenant-Besitznachweis setzt diese Regel ein eigenes
   zufälliges 256-Bit-Ingress-Credential. Es bleibt ausschließlich in der
   Exchange-Regel und im privaten Dienstspeicher. Kein Token in Git, Reports,
   normaler Befehlsausgabe oder Protokollen. Microsoft-IP, sichtbares From,
   `pending` und CrossTenant-Header sind allein kein Herkunftsnachweis.
3. Der zusätzliche Admission-Baustein am bestehenden Plesk-SMTP prüft vor dem
   endgültigen SMTP-250: tatsächlichen Peer und TLS-Kontext, Secret,
   IT-Envelope, genau einen ungefalteten Versionsheader und zugelassene RCPTs.
   Erst danach erzeugt er einen lokalen Kontext für den After-Queue-Filter.
   Das bestehende Plesk-Milter bleibt erhalten. Ein bloßes Secret-Muster in
   `header_checks` ersetzt diese vollständige SMTP-Zulassung nicht.
4. Der Postfix-Content-Filter übergibt CRLF-MIME und originale Envelope-/Queue-
   Metadaten an den eigenen unprivilegierten Pipe-Aufrufer. Vor dem Tausch
   bleiben der erste tatsächliche Eingang und sein Envelope privat erhalten.
   Rohmail und Secret dürfen nicht in Entwicklungsartefakte kopiert werden.
5. Der produktive Resolver bindet die konkrete Quellversion an zugelassene
   veröffentlichte IT-Quelle und unveränderliche Benutzer-/Tenantidentität.
   Alte Header werden nicht still auf die neueste Vorlage umgelenkt. Für
   Retries werden zugelassene Projektion, Medienbytes und Hashes festgehalten.
6. E2 tauscht nur den eigenen eindeutigen Block und übernimmt vorhandene
   Autorenbytes. Der CID-Adapter darf nur geprüfte lokale B1-Medien einbetten;
   niemals URLs aus der eingegangenen Mail herunterladen oder im Zitat ersetzen.
7. **Jeder akzeptierte Rücklauf** entfernt die Routing-Voraussetzung
   `X-RailTime-Template` sowie Ingress-Credential, lokalen Kontext und
   `pending`. Das gilt auch bei unverändertem Inhalt, TNEF oder kontrolliertem
   Transformationsfehler. Ein endlicher Ergebnisheader dient nur der Diagnose.
   Exchange erhält keine Ausnahme aufgrund kopierbarer `v1`-/`done`-Werte.
8. Feste Reinjektion und feste Route zurück zum Tenant-MX, TLS mit
   Servernamenprüfung und passender Clientzertifikatsidentität. Kein direkter
   fremder MX, kein `sendmail -t`, keine globale Plesk-Relayänderung.
9. Erst bestätigte Queue-Übernahme erlaubt Erfolg für die vorherige Queue.
   Fehler beim Speichern, Dienstabbruch oder unbestätigte Rückgabe bedeuten
   Defer/Retry, niemals eine erfolgreiche Originalzustellung vortäuschen.

### Was „bytegleich“ hier bedeutet

Autoreninhalt und Zitat bleiben im **dekodierten HTML** bytegleich. Die neue
Base64-/Quoted-Printable-Kodierung kann Drahtbytes des HTML-Parts verändern.
Unbeteiligte MIME-Teile, Anhänge, Threadheader, Klartextalternative und deren
Payloads bleiben raw erhalten. Bei der CID-Ergänzung darf ausschließlich die
dafür ausgewiesene MIME-Struktur ergänzt werden. Die namentlich genannten
Steuerheader sind eine ausdrücklich erlaubte äußere Änderung. Eine Nachricht
mit unverändertem Inhalt ist daher nach Rücklaufbereinigung nicht als insgesamt
identischer SMTP-Bytestrom zu bezeichnen.

Die neue reine Funktion `../railtime_transit_return.py` prüft diese
Rücklaufgrenze lokal. Falls DKIM/ARC einen zu entfernenden Steuerheader deckt
oder die Headerstruktur unklar ist, verweigert sie die Finalisierung. Der
spätere Aufrufer muss dann zurückstellen und den Fall klären; eine Signatur
wird nicht still ungültig gemacht und ein Secret nicht weitergegeben.

## Frisch gelesener Plesk-Stand, 04.11.2026

Rein lesender SSH-Aufruf erfolgreich:

| Eigenschaft | Bestätigter Wert |
|---|---|
| Postfix | 3.8.6 |
| Öffentliche IPv4 | 82.165.55.230; keine zweite öffentliche IPv4 gefunden |
| Öffentlicher SMTP | bestehender Plesk-smtpd auf Port 25 |
| Bestehender Filter | content_filter leer; Plesk-Milter 127.0.0.1:12768 |
| Eingangs-TLS | global `may`, kein globaler Wechsel vorgesehen |
| Zertifikatsdatei | CN/SAN ausschließlich plesk.follow-flow.de; LE; gültig bis 04.01.2027 |
| Nachrichtengröße | 10.240.000 Byte Postfix; E2 höchstens 10.000.000 Byte |
| Queue-Intervall | 300 Sekunden |
| Retry | mindestens 300, maximal 4.000 Sekunden |
| Queue-Laufzeit | 5 Tage; danach ist ein NDR möglich |

Es wurden keine Queue-Nachrichten gelesen, gelöscht oder erneut eingeliefert.
Ein lesender STARTTLS-Verbindungsversuch vom Windows-Rechner zu Port 25 lief
am 04.11. in ein TCP-Timeout. Es gab dabei keinen SMTP-Dialog und keinen
Mailversand. Ursache (z. B. ausgehende Port-25-Sperre) nicht bewiesen. Die
lokale Zertifikatsabfrage ersetzt daher nicht die externe STARTTLS-Prüfung
vom tatsächlichen Exchange-Hop und die echte Connectorprüfung.

### Frischer Exchange-Readback

Das angemeldete Exchange Admin Center von **RT Rail Time GmbH** zeigt am
04.11.2026 in der vollständig geladenen Liste genau zwei akzeptierte Domänen:
`rail-time.de` (Standard, Authoritative) und
`RTRailTimeGmbH.onmicrosoft.com` (Authoritative). **plesk.follow-flow.de ist
nicht vorhanden.** Es wurde kein Formular zum Hinzufügen einer Domäne oder
eines Connectors abgesendet. Die numerische Tenant-ID wurde in diesem
UI-Readback nicht ermittelt; das PowerShell-Beispiel bleibt dort absichtlich leer.
Die ebenfalls vollständig geladenen Ansichten zeigen **keine Connectoren und
keine Transportregeln**. Als einzige Remote Domain ist `Default (*)`
vorhanden; Rich-Text-Format steht auf **Benutzereinstellungen verwenden**.
Diese Einstellung wurde lediglich gelesen, nicht als Beweis des tatsächlichen
MIME-Formats am späteren Transit-Hop ausgegeben und nicht geändert.

## Vor einer Aktivierungsfreigabe noch offen

| Voraussetzung | Aktueller Status / nächster Nachweis |
|---|---|
| Exchange-Tenant und Accepted Domains | EAC bestätigt RT Rail Time GmbH und zwei Domänen. Exakte Tenant-ID und kompletter PowerShell-Readback bleiben vor StageDisabled erforderlich. |
| Zertifikats-Rückweg | EAC bestätigt: plesk.follow-flow.de ist keine Accepted Domain. Verifikation dieser bestehenden Domäne oder separat genehmigter passender Zertifikatsweg nötig; das vorhandene LE-Zertifikat allein reicht nicht. |
| Admission-Dienst | SMTP-EOD-Zulassung mit Token, echtem Peer/TLS und lokalem Kontext ist noch nicht gebaut/installiert. Postfix-Review ist deshalb keine installierbare Fassung. |
| Produktiver Resolver | Veröffentlichung, IT-Benutzer-/Tenantbindung, Versionssnapshot und private Queueintegration fehlen. Lokale Fixture-Zulassung ist keine Produktionsidentität. |
| Original-SMTP je Host | Erst nach freigegebenem Eingang: eine echte Nachricht aus Classic und aus neuem Outlook am Plesk-Hop. MSG/HTML-Export ist kein Original-MIME. |
| TNEF | Bei echtem TNEF unverändert abschließen, keine Dekodierung. Danach eng wirksame Formatsteuerung prüfen. |
| E4 | Noch keine Transitmail oder neue native iPhone-Abnahme. |

Der vorhandene Smarthostname muss dafür nicht automatisch geändert werden.
Die lesende Prüfung hat bereits ergeben, dass `plesk.follow-flow.de` noch
fehlt. Deshalb bedarf der Verifikations-/Zertifikatsweg einer konkreten
Entscheidung; das Paket legt keine neue Subdomain an und ändert kein DNS.
Ein IP-basierter Connector wird nicht als ungeprüfter Ersatz verwendet.

## TNEF: keine scheinbar zielgenaue Änderung

`TNEFEnabled=false` gilt für die **Empfängerdomäne**, nicht für den Namen des
Connector-Smarthosts. Eine neue Remote Domain `plesk.follow-flow.de` belegt
deshalb keine MIME-Konvertierung einer Nachricht an `it-technik@rail-time.de`.
Erst tatsächlichen Eingang sichern; dann die wirksame und eng begrenzte
Einstellung mit Readback und Rückbau festlegen. `Default` oder alle
`rail-time.de`-Mails werden in dieser Vorbereitung nicht verändert.

## Ausführung und Abnahme nach gesonderter Freigabe

1. In einer bereits authentifizierten Exchange-Admin-Sitzung zunächst
   `exchange-pilot.ps1 -Action Inspect` aufrufen. Keine automatische Anmeldung
   und keine Testmail. JSON-Beispiel mit bestätigtem Tenant und Sourcepin
   vervollständigen. Ausgaben enthalten keine Secretwerte.
2. Zertifikats-/Accepted-Domain-Frage und Admission/Resolver schließen. Dann
   die konkret geprüften Postfix-Änderungen und deaktivierten Exchange-Objekte
   separat freigeben. Kein Installer aus dem alten Entwurf übernehmen.
3. Erst bei betriebsbereitem Rücklauf den befristeten Pilot separat aktivieren.
   Zwei genau gekennzeichnete Nachrichten: Classic → IT und neues Outlook → IT.
   Keine externe Adresse ohne zusätzliche konkrete Zustimmung.
4. Originale MIME-/Envelope-/TLS-Belege privat am ersten Plesk-Eingang sichern;
   öffentlich nur Länge/Hashes, Host und endliche Ergebnisse festhalten.
5. E2 auf diese Originale anwenden: exakte Version, HEAD-CSS/Viewport/CID,
   Autoren-/Zitatvergleich, Anhänge/Plaintext/Thread unverändert. TNEF-Fälle
   zuerst als solche dokumentieren und den Formatweg klären.
6. iPhone mit `[TEST U2e]` vom 03.11. 19:07 vergleichen; Classic und neues
   Outlook im Lesebereich unverändert; echte Spalten 848/1040/1280 px prüfen.
   Browser-Messung und native Geräteabnahme getrennt protokollieren.
7. Gefälschte Header/anderer Tenant, Secretverlust, Pipelineabbruch,
   Weiterleitung/Antwort mit Zitat, Anlagen sowie unveränderte Rückläufe
   prüfen. Keine Duplikatfreiheit aus SMTP behaupten: Ein Absturz unmittelbar
   nach bestätigter Annahme kann einen Wiederholungsfall erzeugen.

`Validate-OutboundConnector` ist **kein lesender Test**: Es versendet echte
Testnachrichten. Das Skript ruft es nicht automatisch auf.

## Abschalten und Queue/Retry

Neue Umleitungen durch Deaktivieren ausschließlich der Pilotregel stoppen.
Connectoren, Admission, Eingang und Rückweg bleiben für bereits angenommene
Pilotnachrichten bis zum geprüften Queue-Drain verfügbar. Ausschließlich die
eigenen Queue-IDs bearbeiten; keine fremden Plesk-Queues leeren. Keine
wartende Mail löschen und keinen automatischen direkten Fallback versprechen.
Ein gesperrter Connector oder ausgefallener Host kann Exchange-seitig
zurückstellen; konkrete Exchange-Trace-/NDR-Zeiten müssen im Piloten gemessen
werden und werden nicht aus den Postfix-Werten abgeleitet.

## Primärquellen, am 04.11.2026 geprüft

- [Microsoft Add-on-Integration](https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/use-connectors-to-configure-mail-flow/integrate-office-365-with-an-email-add-on-service)
- [Inbound-FAQ: Zertifikatsdomäne statt IP-Bypass](https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/use-connectors-to-configure-mail-flow/inbound-connector-faq)
- [New-InboundConnector](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/new-inboundconnector?view=exchange-ps)
- [New-OutboundConnector](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/new-outboundconnector?view=exchange-ps)
- [New-TransportRule](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/new-transportrule?view=exchange-ps)
- [Remote-Domain/TNEF](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/set-remotedomain?view=exchange-ps)
- [Validate-OutboundConnector sendet](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/validate-outboundconnector?view=exchange-ps)
- [Postfix Pipe](https://www.postfix.org/pipe.8.html), [Milter](https://www.postfix.org/MILTER_README.html), [After-Queue](https://www.postfix.org/FILTER_README.html), [TLS](https://www.postfix.org/TLS_README.html)

Die Microsoft-Add-on-Beispielsyntax verwendet `RestrictDomainsToCertificate`
bei OnPremises, während die Parameterreferenz die Wirkung auf Partner
begrenzt. Diese widersprüchliche Stelle ist kein bewiesener Sicherheitsguard.
Das Skript setzt diesen Schalter nicht und bleibt deaktiviert; das tatsächliche
Connectorverhalten muss vor Aktivierung separat bestätigt werden.
