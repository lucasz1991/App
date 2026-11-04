# C-K17: lokal ausführbares, deaktiviertes E3-Paket

Stand 04.11.2026. Keine Installation, kein Connector-/Regel-/DNS-/Postfix-Schreibzugriff, kein Socket-/SMTP-Test, kein Versand. Produktionsvorlage v2 bleibt. Persönlicher Pilot sowie vollständige Envelope-Allowlist bleiben ausschließlich `it-technik@rail-time.de`. E1: Editor-Kopie bleibt, kein Graph.

## Was jetzt implementiert ist

`railtime_e3.py` enthält RCPT-/EOD-Zulassung, authentifizierte unveränderliche Tickets, private erste Milter-/Queue-Eingabe, Snapshotbindung, atomisches Retryjournal, CID-/E2-Aufruf und Ergebnisabschluss. `railtime_e3_protocol.py` implementiert die Postfix-Policy- und Milter-Sitzungen. `railtime_e3_cli.py` stellt die deaktivierten Unix-Socket-Daemons, Pipe und feste lokale Reinjektion bereit. Keine Funktion öffnet beim Import einen Listener oder sendet eine Mail. `inspect` liest nur Konfiguration; jede Betriebsaktion verweigert Windows und Root-Ausführung und benötigt die separate gebundene Freigabe.

Privater B1-Resolver: `resolve-b1.php` ruft den read-only `OutlookUniversalTransitSnapshotProvider` auf. Nur echte Tenant-/immutable-Benutzer-/aktive-M365-/IT-/Veröffentlichungsbindung darf einen exakten aktuellen B1-send-Snapshot samt 13 hashgeprüften lokalen PNGs liefern. Keine Profilwerte oder Mailbytes im Resolverrequest. `transform-b1.php` verwendet die vorhandene geprüfte E2/CID-Bibliothek, ohne Laravel-Kernel, .env, DB oder Transport. Der private JSON-Callback ist keine HTTP-Route.

## Vor SMTP250

1. Für IT-RCPT ist eine enge Policyprüfung **vor** `reject_unauth_destination` nötig, weil Plesk dieses Postfach nicht lokal führt. Nur tatsächlicher verifizierter Microsoft-SMTP-Peer, TLS1.2/1.3 mit mindestens128Bit, exakter IT-MAILFROM und exakter IT-RCPT ergeben `OK`. Andere normale Empfänger ergeben `DUNNO`, keine pauschale Microsoft-Relayfreigabe. Ein plusadressierter, fremder oder gemischter finaler Envelope wird nicht übernommen.
2. Die EOD-Zulassung verlangt zusätzlich genau einen ungefalteten 256Bit-Ingress-Secret-Header und genau die ausdrücklich freigegebene Vorlagenkennung. Kopierte Resultat-/Pending-/Local-/Contextheader verleihen keine Rechte. Fehlende/falsche Secrets dürfen nie auf die normale Route zurückfallen. TLS/Peer/Queue-ID stammen ausschließlich aus tatsächlichen Milter-Frames/-Makros; Queue-ID erst bei EOM verlangen.
3. Der feste lokale Resolver läuft vor Annahme. Quelle/Version nicht mehr aktuell: kontrollierte unveränderte Rückgabe mit Headerabschluss, niemals still auf die neueste Vorlage umleiten. Resolver-/Identitäts-/Medien-/Races-/Spoolfehler: vor250 temporär ablehnen.
4. Ticket und erster Capture werden exklusiv mit `O_EXCL`/`O_NOFOLLOW`, privaten Dateirechten, fsync und Verzeichnis-fsync geschrieben. Ticketmetadaten einschließlich Snapshot/Capturehash sind HMAC-gebunden; das veränderliche SQLitejournal ist getrennt. Maximal32 Tickets und zusätzliche Größenbremse; Kapazitätsmangel ist 451, kein Datenverlust.

Die Peerbereiche sind nur Infrastrukturevidenz. Der offizielle Microsoft-Endpoint10/tcp25-Snapshot vom04.11.11:08:50UTC enthält sechs Netze; weder diese IPs noch ein sichtbares From oder kopierbare CrossTenantfelder beweisen den Tenant. Die belegte öffentliche Tenant-ID ist `bc4d2441-c79f-4057-a72f-6494c9b66a66`; der aktive Benutzer und die privaten OS-/Dateiparameter werden nicht erfunden.

## Bytebindung, Queue und Rücklauf

Milter-Protokoll6 muss `HDR_LEADSPC` anbieten. Erst dann werden Name, Doppelpunkt, ursprüngliche führende Spaces/Tabs, Faltung und Body zur Bindung zusammengesetzt. Eigene hinzugefügte Headerwerte enthalten ihren führenden Space ausdrücklich. Postfix versteckt seinen eigenen Received-Header vor dem Milter; der Pipe-Aufrufer lässt daher nur **eine neu vorangestellte** Received-Zeile mit genau dem lokalen Host und der tatsächlichen Queue-ID zu. Alle ursprünglichen Header und der Body müssen sonst bytegleich sein. Kein allgemeines Entfernen von Trace-/Authheadern. Die tatsächliche Plesk-Milter-Reihenfolge und diese Grenze müssen vor Aktivierung auf Postfix3.8.6 physisch belegt werden.

Die Pipe läuft unprivilegiert, erhält tatsächliche Queue-ID/Clientadresse/MAILFROM/alle finalen RCPTs per unterstützten Postfix-Argumenten und prüft sie erneut gegen das Ticket. So autorisiert ein vorheriger RCPT nicht nachträgliches Aliasing/Auto-Bcc; fehlende Pilot-Alias-/BCC-Umschreibungen sind ein eigener Preflight. Kein Shellaufruf, kein `sendmail -t`. `eol=\r\n`, keine datenhinzufügenden Pipeflags. Gesicherte Queue-Eingabe und Snapshot liegen nur im privaten Spool, niemals in Git/Berichten.

Jeder Claim erhält eine unvorhersagbare Lease-Ownerkennung. Prepare, BeginReturn, Complete und Retry verwenden CAS und passende Zustände. Ein abgelaufener alter Worker darf weder neue Ausgabe schreiben noch `submitting`/`submitted` zurücksetzen. Ausgabe wird vor Reinjektion unveränderlich gespeichert und bei bewiesenem Nicht-Annehmen identisch wiederholt. Erfolg erst nach tatsächlichem lokalen Postfix250 und passender zuvor vom Return-Milter gebundener Queue-ID. Crash/Verbindungsabbruch nach DATA oder unklarer ACK: `uncertain`, 75/Defer und manuelle Queueprüfung, kein blindes Wiederholen und keine Exactly-once-Behauptung.

**Der lokale SMTP-Rückweg vertraut nicht bloß Loopback.** Ein privates einmaliges vorbereitetes Returnticket bindet den kompletten bereinigten Payload und den exakten IT-Envelope. Der Return-Milter an127.0.0.1:10027 prüft es vor250 und entfernt den lokalen Kontextheader. Dieser Listener darf ausdrücklich **nicht** `no_milters` erhalten. Eine andere lokale Anwendung ohne Ticket wird abgewiesen. Die gespeicherte Route ist ausschließlich `railtime-return:[railtime-de01b.mail.protection.outlook.com]:25`, mit verifiziertem TLS-Servernamen und dem separat freizugebenden Clientzertifikat. Keine fremde MX-Auswahl.

Jeder kontrollierte Rücklauf entfernt `X-RailTime-Template`, Ingresssecret, Pending und lokale Kontext-/Admissionfelder. Die Exchange-Regel braucht dann keine kopierbare done/v1-Ausnahme: Ihre Routingvoraussetzung fehlt. TNEF, geschützte Nachricht, unbekannte HTML-Struktur oder kontrolliert abgelehnte Quelle ergeben unveränderten MIME-Payload mit diesem sicheren Headerabschluss. Deckt eine DKIM-/ARC-Signatur einen zu entfernenden Steuerheader, wird zurückgestellt statt Signatur oder Secret still zu beschädigen. Vorhandene DKIM-/ARC-Transport-Signaturen verhindern weiterhin HTML-Veränderung ohne nachgewiesenen Re-Signaturvertrag.

## Berechtigungen, Konfiguration und echte fehlende Belege

- `runtime-config.example.json` bleibt `enabled:false`, userId0 und ohne echte Secrets/OS-Pfade. Die Konfiguration und `activation.json` müssen root-owned und nicht durch den Transituser beschreibbar sein. Es gibt keinen Enable-/Installbefehl und keine ausgefüllte Aktivierungsdatei im Paket.
- Separater unprivilegierter Transituser, privater Spool0700, Ticket-/Capture-/Outputdateien0400, Journal0600. Credentialdateien nur dedizierte Transitgruppe lesbar. Socket-Unterordner fest `TRANSIT_USER:postfix` mit2750: SGID vererbt den Postfix-Gruppeneigentümer auf die660-Sockets. Der Daemon prüft diesen Vertrag; der Transituser erhält dafür keine pauschale zusätzliche Postfix-Gruppenmitgliedschaft. Keine App-.env-/DB-/Deploymentrechte an den Filteruser.
- Resolverausführung nötigenfalls ausschließlich unter dem echten App-OS-User über eine enge root-owned sudoers-Regel: exakt PHPbinary+genau `resolve-b1.php`, keine Shell/Wildcards/SETENV/zusätzlichen Argumente. Snapshotstdout und stdin nicht transkribieren. Der Admissiondienst darf für diesen einen sudo-Aufruf nicht `NoNewPrivileges=true` erhalten; Return/Policy/Cleanup benötigen diese Ausnahme nicht.
- Der aktuelle Zertifikatsname `plesk.follow-flow.de` fehlt laut EAC in Accepted Domains. Zertifikats-/tenant-eindeutiger Accepted-Domain-Weg, Rückweg-MX und tatsächliche Connectorzuordnung bleiben vor Aktivierung separat zu bestätigen. Keine neue Subdomain/DNS/AcceptedDomain wird hier angelegt; kein IP-Bypass als Ersatz.
- Frischer nur-lesender Root-Preflight bestätigte DNS-MX und Postfix3.8.6. Ein STARTTLS-/Hostnameprüfaufruf zum Tenant-MX beendete sich mit Exit1 ohne Ausgabe; der Rückweg ist damit **nicht positiv bewiesen**, seine Ursache nicht bestimmt. Kein MAIL/RCPT/DATA wurde gesendet und keine Portblock-Ursache behauptet. Der verpflichtende echte Rückweg-TLS-Nachweis bleibt bestehen.
- Windows-Offline-/Byte-/SQLite-/PHP-Tests sind kein Nachweis von UID/GID, Unix-Socketrechten, systemd, Postfix-Makro-/Milter-/Policyverhalten oder echtem SMTP. Kein Linuxcontainer/Cloudcodeupload wurde dafür eingerichtet.

## Verfügbarkeit und Abschalten

Der Generator übernimmt die **effektive** bisherige Plesk-Milterliste und ergänzt unseren Filter nur für den frischen sechs Microsoft-SMTP-Peerbereichen zugeordneten Kontext. Ein vorhandenes leeres führendes Listenelement entfällt, keine effektive Filteradresse. Per-Milter `default_action=tempfail` ändert nicht die globale Plesk-Failurepolicy. Bestehende nichtleere milter_map/header_checks werden nicht blind überschrieben; sie benötigen einen eigenen Merge-Review.

Dieser Shared-Port-Kompromiss ist sichtbar freizugeben: Bei Ausfall unseres Milters können auch normale Plesk-Mails **aus diesen Microsoft-Netzen**451 erhalten. Andere Peerbereiche bleiben auf der alten Liste. Eine wirklich isolierte zusätzliche SMTP-IP wäre eine eigene Infrastrukturentscheidung. `default_action=accept` ist kein erlaubter Ausweg. Root muss die konkrete effektive Milterauswahl vor Aktivierung belegen.

Neuzulassung und Drain sind getrennt: maximal24h neue Zulassung, mindestens sieben weitere Tage für bereits angenommene Tickets. Das Ende des Ingressfensters schaltet Pipe/Return nicht ab; sie benötigen weder frische MS-Peerzuordnung noch das Ingresssecret erneut. Bei Rollback zuerst ausschließlich die neue Pilotregel deaktivieren. Eigene Exchange-/Plesk-Queues inventarisieren; Connectoren, lokale Returnstrecke und Ticketkeys bis zum bestätigten Drain erhalten. Keine fremde Queue löschen, keine Originalzustellung bei Filterausfall vortäuschen. Ein bereits Exchange-seitig auf den Connector festgelegter Retry braucht gesonderte Operatorentscheidung; keine automatische Neuauswertung der Regel behaupten.

Ticketzulassung läuft nach sieben Tagen ab. Das ist **keine pauschale harte Rohmail-Aufbewahrungszusage**: `cleanup` entfernt nur abgelaufene, bestätigt abgeschlossene eigene Artefakte und Journalzeilen. Ein vor Dateilöschung gespeicherter `purging`-Tombstone macht Teilabbrüche idempotent. Aktive/unklare abgelaufene Fälle bleiben geschützt, werden als `expiredUnresolved`/Alarm gemeldet und benötigen private manuelle Queueklärung. Die deaktivierten Cleanup-service/timer-Dateien stellen einen Stundenplan zur Prüfung bereit; kein Timer wurde installiert. Quota begrenzt weitere Annahme bei ungelösten Altfällen.

## Konkrete getrennte E3-Freigabe

Vorlage für die spätere Zustimmung: exakter Commit/Quellmanifest; tatsächlicher Tenant/Benutzer/OS-Pfad; verifizierter Accepted-Domain-/Zertifikats-/TLS-/Rückweg; frischer Postfix-/Alias-/BCC-/Milter-/Peernetz-Snapshot; exakt generierter Main-/Master-/Map-/sudoers-/Service-Diff; private Secretbereitstellung ohne Ausgabe; Aktivierungsfenster/Drain/Stop-Regel; nur IT→IT; Privatablage und Retention; festgelegter Operator für451/uncertain/NDR/Queue-Drain.

Erster freigegebener SMTP-Eingang aus Classic und neuem Outlook wird privat gesichert und als MIME/TNEF/protected klassifiziert. TNEF/protected ergibt kontrollierten Originaldurchlauf mit Headerabschluss und **keine responsive Erfolgsmeldung**. Keine globale Rich-Text-/RemoteDomainänderung und keine TNEF-Dekodierung. Erst tatsächliche Originale plus E2-/Autoren-/Zitat-/Anlagenbeweis und die nachfolgende iPhone-/848/1040/1280px-Abnahme können E4 erfüllen. Gesendete Elemente bleiben die Editorfassung.

## Lokale Ausführung ohne Netz

```powershell
$env:PYTHONDONTWRITEBYTECODE='1'
python -B scripts/mail/e3/test_railtime_e3.py
python -B scripts/mail/e3/railtime_e3_cli.py inspect --config scripts/mail/e3/runtime-config.example.json
powershell -NoProfile -File scripts/mail/e3/test-exchange-pilot.ps1
```

Die Exchange-Skripte bleiben Get-only/StageDisabled/Rollback; `Validate-OutboundConnector` sendet tatsächlich und wird nicht aufgerufen. Reviewgenerator benötigt einen echten frischen Baseline-JSON und schreibt nur einen **neuen lokalen Reviewordner**, niemals `/etc/postfix`.

## Primärquellen des technischen Vertrags

- [Microsoft Add-on-Connector und tenant-eindeutige Zertifikatsdomäne](https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/use-connectors-to-configure-mail-flow/integrate-office-365-with-an-email-add-on-service)
- [Offizieller Microsoft-Endpointdienst](https://learn.microsoft.com/en-us/microsoft-365/enterprise/microsoft-365-ip-web-service)
- [Postfix Milter, Makrostufen, maps und per-Milter-Optionen](https://www.postfix.org/MILTER_README.html)
- [Postfix-Milter-Protokollimplementierung: HDR_LEADSPC](https://github.com/vdukhovni/postfix/blob/master/postfix/src/milter/milter8.c)
- [Milter-Protokollentwurf: Header-Whitespace und Protokollflag](https://datatracker.ietf.org/doc/draft-adams-milter-protocol/)
- [Postfix After-Queue-Verarbeitung](https://www.postfix.org/FILTER_README.html), [Pipe und Exitstatus](https://www.postfix.org/pipe.8.html), [TLS-Namensprüfung](https://www.postfix.org/TLS_README.html)

Die Quellen belegen die Mechanismen, keine auf diesem Plesk-Host bereits
erfolgte native Integration. Öffentliches Microsoftnetz und DNS-MX allein
beweisen weder private Tenant-Zulassung noch einen funktionierenden Rücklauf.
