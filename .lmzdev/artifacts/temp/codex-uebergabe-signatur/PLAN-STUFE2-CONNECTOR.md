# Stufe 2: Signatur über Exchange-Connector, nur für it-technik@rail-time.de

Stand 03.11.2026, C-K7 nach dem Auftrag von 23:45. Dieser Plan beschreibt Stufe 2 von `PLAN-EDITORWEG.md`; Outlooks eigener „Senden“-Knopf, sichtbare Editorfassung und unveränderte Zitate bleiben verbindlich. C-K7 ergänzt ausschließlich einen inaktiven strukturellen Leser und redigierte Tests. Kein Transitdienst, Connector, Routing, Grant oder Mailversand wird dadurch eingerichtet. Die untenstehenden Serverdaten und Konfigurationsskizzen stammen weiterhin vom 02.11.; vor einer später freigegebenen Umsetzung sind sie erneut zu prüfen. Für Erkennung und Inhaltsgrenzen gilt der folgende C-K7-Vertrag.

## C-K7: Verbindlicher Erkennungsvertrag

### Vertrauensgrenze vor der HTML-Erkennung

Eine Kopfzeile ist kein Herkunftsnachweis. Der spätere Transitaufrufer muss zuerst den ausdrücklich freigegebenen Connector und TLS-Kontext, den eigenen Tenant und den authentifizierten Envelope-Absender aus der Pilot-Allowlist prüfen. Ein sichtbares `From`, eine Microsoft-IP, `X-RailTime-Transit: pending` oder `X-RailTime-Template` allein genügen nicht. Nicht vertrauenswürdig eingelieferte Transit-/Ergebnisheader dürfen keine Freigabe oder Schleifen-Ausnahme bewirken. Diese Kontrollen sind noch nicht implementiert; die boolesche `trustedIngress`-Eingabe des isolierten Lesers stellt lediglich eine vom künftigen Aufrufer bereits bewiesene Voraussetzung dar.

Nach dieser Schranke muss genau eine Kopfzeile `X-RailTime-Template: u2e-<24 kleine Hexzeichen>` vorliegen; fehlende, doppelte, widersprüchliche oder mehrzeilige Werte bedeuten unveränderte Weitergabe. Die Version muss zusätzlich gegen die für den authentifizierten Absender zugelassene veröffentlichte Quelle aufgelöst werden. Der isolierte Leser prüft nur einen **Kandidaten**, weder diese Veröffentlichung noch eine Berechtigung oder den Zustand „aktuell“.

### Struktur und Bytegrenzen

- Genau eine eigene 1040-Pixel-Hülle vor einer bekannten Zitatgrenze. Der derzeit belegte Umfang ist die kombinierte U2e-Fassung mit vier äußeren Zeilen: Autorenzeile `colspan=4`, vier Zellen 260/280/213/287, Dispo-Zelle `rowspan=2`, Zugzeile `colspan=3`, Rechtszeile `colspan=4`.
- Eine 247-Pixel-Dispotabelle in der vierten Zelle, 12 bis 14 Signaturbilder je nach tatsächlichen Kontaktdaten und genau ein Zugbild 500×83 in der Zugzeile. Der konkrete Empfang 23:33 besitzt 13 Bilder. Word-Werte in `pt` und `in` werden in Pixel umgerechnet; widersprüchliche Breitenattribute bleiben eine Ablehnung.
- Hüllen- oder Zell-IDs sind nur Hinweise. Sie sind weder notwendig noch allein maßgeblich und überschreiben niemals die Header-Version. Ohne vertrauenswürdige Einlieferung bleibt selbst eine passende Kopfzeile mit passender Struktur wirkungslos.
- `blockquote`, bekannte Outlook-/Webmail-Zitatgrenzen und der strukturell erkennbare Word-Antwortkopf begrenzen den eigenen Bereich. Ein Kandidat muss vollständig **vor** der Grenze enden; eine äußere Tabelle, die einen Zitatkopf einschließt, darf das Zitat nicht mitbesitzen. Zwei eigene Kandidaten, ausschließlich zitierte Kandidaten und unbekannter sichtbarer Text vor der Hülle führen zu unveränderter Weitergabe.
- Standalone-Signaturen hinter freiem Nachrichtentext sind in diesem ersten, empfangsbelegten Leser nicht zugelassen. Dafür fehlt eine belastbare Bytegrenze zwischen Autorentext und älterer Signatur. Nicht eindeutige Fälle bleiben unangetastet, bis eigene Belege diesen Umfang erweitern.
- Der Leser liefert nur Byteoffsets, Typ, Bildzahl und Hash. Er serialisiert kein DOM, schreibt keinen HTML-Teil und entfernt keine Zitatstile. Der spätere Ersetzer muss aktuelle, bereits geschriebene Autorenbytes übernehmen; er darf den Nachrichtentext nicht aus einer frisch gerenderten Vorlage ersetzen. Präfix, Zitatsuffix, Klartextalternative, Anlagen und Threadheader bleiben unverändert. Kopf-CSS muss so begrenzt werden, dass es zitierte ältere Blöcke nicht nachträglich gestaltet.

### C6 bleibt von Transit und „aktuell“ getrennt

Ein strukturell erkennbarer alter Block im Zitat ist kein aktueller eigener Block. Die Kopfzeile der Nachricht ermächtigt keinen Austausch im Zitat. Der vorhandene Compose-Leser wurde rein lesend mit dem empfangenen 23:33-HTML und einer ausdrücklich daraus abgeleiteten Variante ohne IDs geprüft: Hinter `divRplyFwdMsg` bleibt `present=false/current=false`; ohne bekannte Word-Zitatgrenze bleibt der Bereich unlesbar und die Einfügung gesperrt. Aus diesem Beleg ergibt sich kein bestätigter automatischer Überschreibfehler und kein Anlass, die bestehende Sperre zu lockern. Eine automatische Migration bearbeiteter V17-Entwürfe ist weiterhin nicht nachgewiesen.

### Empfangsbeleg, redigierte Fixture und Tests

`empfangen-classic-word-2333.html` hat SHA-256 `ad5a44eb84a5eb40d421c7d1ebb7fac2f4f088f9ef14a72d8e4c21e97c498b49`, 14781 Byte, zehn Tabellen und 13 Bilder. Die konkrete Datei hat keine Hüllen-ID, aber **eine erhaltene Zell-ID mit `-cell`**; vollständiger ID-Verlust ist für diese Datei nicht belegt. Die Markerlos-Variante wird im Test ausdrücklich abgeleitet. Der Headerabzug hat SHA-256 `6cd3ebb72515e09d7a51ee2393b06706553989e8990cb5b02be205344f62fe1f`; nach Entfernen des UTF-8-BOM ist genau ein gültiger Templateheader vorhanden. Originale Geschäftsdateien werden nicht in Git übernommen.

Der reproduzierbare Generator unter `tests/Fixtures/mail/u2e-transit/generate-redacted-fixture.py` akzeptiert nur diese beiden Originalhashes. Er ersetzt sämtliche Texte und URLs, entfernt Kopf, Kommentare und unbekannte Attribute und erhält die benötigte Word-Geometrie. Die öffentliche Fixture ist strukturelle Evidenz, kein Pixel- oder nativer Clientnachweis. Ihre Provenienz und LF-normalisierten Hashes stehen in `manifest.json`.

Isolierter Leser: `app/Support/Mail/OutlookUniversalTransitCandidate.php`; gezielte Tests: `tests/Unit/OutlookUniversalTransitCandidateTest.php`. Es gibt keinen produktiven Aufrufer. Geprüft werden vertrauenslose Header, Duplikate, Geometrieverlust, Hüllen über Zitatgrenzen, markerlose Zitate, zwei eigene Blöcke und unveränderte Eingabebytes. Der Plan verlangt weiterhin die spätere echte Transport- und MIME-Abnahme aus Block E; diese Tests aktivieren sie nicht.

## Warum dieser Weg
Das Add-in übergibt die Signatur an den Editor des neuen Outlook. Der Editor entfernt Stilblöcke, `@media`, Klassen und `!important` (Belege R783, R786). Der Weg über Exchange setzt die Signatur **nach** dem Editor ein. Dass Kopf-CSS mit `@media` dann vollständig beim Empfänger ankommt, ist mit den EML-Tests 1 bis 3 belegt, auf dem iPhone bestätigt.

## Ablauf im Pilot
1. Nutzer schreibt wie heute in Outlook. Das Add-in fügt die U2e-Editorfassung ein; sie dient als Vorschau und Rückfall. IDs können beim Empfang verschwinden und sind keine Transportvoraussetzung.
2. Beim Senden greift eine Exchange-Regel nur für Absender `it-technik@rail-time.de`: Sie setzt die Kopfzeile `X-RailTime-Transit: pending` und leitet die Mail über einen ausgehenden Connector an den RailTime-Server `plesk.follow-flow.de` (82.165.55.230).
3. Postfix auf dem RailTime-Server nimmt die Mail an und gibt sie wegen der Kopfzeile an den Dienst `railtime-transit`.
4. Nach bewiesenem Einlieferungskontext und zugelassener Header-Version wählt der Dienst nur den eindeutigen eigenen Strukturkandidaten nach C-K7. Er ersetzt ausschließlich den freigegebenen Signaturbereich durch die Versandfassung und übernimmt den tatsächlichen aktuellen Autorentext. Zitate bleiben einschließlich vorhandener Stilblöcke byteweise unverändert; die bisherige Prototyp-Bereinigung ist dafür nicht zugelassen. `In-Reply-To` und `References` bleiben unverändert. Jeder Fehler führt zur unveränderten Originalmail.
5. Postfix liefert ausschließlich an den eigenen Tenant (`railtime-de01b.mail.protection.outlook.com`) zurück. Ein eingehender Connector erkennt den Server am Zertifikat, Exchange stellt dann normal zu, auch an externe Empfänger.
6. Die Regel greift beim zweiten Durchlauf nicht, weil `X-RailTime-Transit: v1` gesetzt ist.
7. **Gesendete Elemente nachziehen** (Entscheidung des Nutzers: auch dort immer responsive): Der Dienst legt über Microsoft Graph mit Anwendungsrecht `Mail.ReadWrite`, per Application Access Policy auf `it-technik@` begrenzt, die getauschte Mail als Kopie in „Gesendete Elemente“ ab und entfernt die Editor-Fassung. Graph erlaubt kein Ändern des Textes einer gesendeten Mail (`body` nur bei `isDraft = true`, [Microsoft](https://learn.microsoft.com/graph/api/message-update)); darum Kopie statt Änderung: MIME der getauschten Mail per `POST /users/{id}/messages` anlegen, Kennzeichen „gesendet“ über die MAPI-Eigenschaft `PR_MESSAGE_FLAGS` setzen, nach „Gesendete Elemente“ verschieben, Original anhand der `Message-ID` löschen. So arbeitet auch Exclaimers „Sent Items Update“ ([Exclaimer](https://support.exclaimer.com/hc/en-gb/articles/360020858898-Sent-Items-Update)), seit 2026 über Graph statt EWS. Scheitert der Schritt, bleibt die Editor-Fassung in „Gesendet“; die Zustellung ist davon unabhängig.

Der Server sendet nie direkt an fremde Empfänger. SPF bleibt deshalb unverändert, Microsoft bleibt der einzige Versender.

**Versandfassung (aktuell U2e):** Vorgesehen ist die gemeinsame `OutlookUniversalPresentation` mit `mode=send`, aktueller zugelassener Personen-/Firmen-/Dispoquelle und den tatsächlichen Autorenbytes. Maßstab ist unverändert `RailTime-Vorlage-U2.html`. Zwei `@media`-Stufen und die vollständigen Metas gehören zur Versandfassung, nicht in den Editor. Der alte Handoff-Renderer ist kein automatisch freigegebener Ersatz. R787 belegt früheren MIME-Transport, nicht die native U2e-Abnahme oder eine funktionierende Transitstrecke.

## Gegebenheiten (geprüft am 02.11.2026, nur lesend)
| Punkt | Befund |
|---|---|
| Server | `plesk.follow-flow.de` = 82.165.55.230, Ubuntu 24.04, Plesk; Root-SSH per Schlüssel |
| Postfix | 3.8.6, lauscht auf 25 und 465, von Plesk verwaltet; `smtpd_tls_security_level = may`, Zertifikat `/etc/postfix/postfix.pem`; kein `content_filter`, Plesk-Milter auf 12768 |
| Laufzeiten | PHP 8.3.6, Python 3.12.3 |
| Postfächer | `app.rail-time.de` hat Mail-Dienst an, aber 0 Postfächer; der Server empfängt heute keine Mail für rail-time.de |
| DNS rail-time.de | NS bei udag; MX Exchange Online; SPF `include:spf.protection.outlook.com -all`; **kein DMARC, kein DKIM (selector1/2 fehlen)**; `transit.rail-time.de` existiert nicht |
| App-Versand | über `smtp06-ext.udag.de`, dadurch heute `spf=fail` bei App-Mails (eigenes Thema, nicht Teil des Pilots) |
| Prototyp | `code/transit-signatur.php.txt`: HTML-Austausch mit der aktuellen Vorlage geprüft; kein MIME, keine Absenderdaten |

## Phase 0: Entscheidungen des Nutzers (getroffen am 02.11.2026)
- **Bilder:** so lassen wie momentan. Der Dienst übernimmt die Bildreferenzen, wie das Add-in sie heute setzt, und wechselt nicht still auf CID. Die Regel aus `AGENTS.md` zu eingebetteten Medien bleibt eine offene Aufgabe für später, nicht Teil des Pilots.
- **Gesendete Elemente:** müssen ebenfalls responsive sein. Deshalb Schritt 7 im Ablauf (Kopie über Graph). Das ist ein zweiter Schreibweg mit eigenem Recht und eigenem Risiko; „nicht garantiert“ wie bei Exclaimer.
- **Ausfall:** wie bisher belassen: Exchange hält Pilot-Mails bei nicht erreichbarem Server bis zu 24 Stunden zurück, nichts geht verloren.
- **Zertifikat:** keine neue Subdomain. Der Connector spricht `plesk.follow-flow.de` an. Postfix präsentiert auf Port 25 bereits das Let's-Encrypt-Zertifikat `CN=plesk.follow-flow.de` (gültig bis 04.01.2027, Plesk erneuert automatisch; geprüft am 02.11.2026 per `openssl s_client`). Das reicht für `TlsDomain` und `TlsSenderCertificateName`.
- **Dispo-Daten:** so lassen wie momentan (WhatsApp `+49 160 1881848`, Telefon `+49 160 1881839`, Karte wie in der Live-Signatur).

## Phase 1: Server vorbereiten (kein Eingriff in den Mailfluss)
Alles in dieser Phase ändert für keine Mail etwas, solange die Exchange-Regel fehlt.

1. **DNS:** keine Änderung. `plesk.follow-flow.de` zeigt bereits auf 82.165.55.230.
2. **Zertifikat:** keine Änderung. Postfix liefert auf Port 25 `CN=plesk.follow-flow.de` von Let's Encrypt, Plesk erneuert es. Vor Phase 2 einmal mit `openssl s_client -starttls smtp` von außen prüfen, dass der öffentliche Port dasselbe Zertifikat zeigt.
2a. **Graph für „Gesendete Elemente“:** App-Registrierung im Tenant mit Anwendungsrecht `Mail.ReadWrite`, Admin-Einwilligung, dazu `New-ApplicationAccessPolicy` auf `it-technik@` begrenzt, Geheimnis nur auf dem Server. Ohne Dienst wirkungslos.
3. **Postfix, minimale Änderungen** (Plesk kann `main.cf` bei Updates neu schreiben, deshalb nur drei Stellen und jede dokumentieren):
   - `header_checks`: Zeile `/^X-RailTime-Transit:\s*pending$/ FILTER railtime-transit:dummy`. Nur Mails mit dieser Kopfzeile laufen durch den Dienst, alle anderen Plesk-Mails bleiben unberührt.
   - `master.cf`: Transport `railtime-transit` als `pipe` an `/usr/local/bin/railtime-transit`, Benutzer `railtime-transit` ohne Shell.
   - `master.cf`: Transport `transit-return` (smtp) mit `-o relayhost=[railtime-de01b.mail.protection.outlook.com]:25` und `-o smtp_tls_security_level=encrypt`; `transport_maps` leitet alles, was der Dienst zurückgibt, über diesen Transport. Der Server liefert nie an fremde MX.
   - `smtpd_client_restrictions`: Relay nur von Microsoft-365-Adressbereichen (Liste aus der Microsoft-Endpunktliste, regelmäßig aktualisieren) und nur mit Kopfzeile `pending`. Zu prüfen: Plesk setzt eigene Restriktionen, Reihenfolge beachten.
   - `message_size_limit` steht auf 10 MB. Exchange erlaubt mehr. Für den Pilot reicht es, für den Ausbau anheben.
4. **Dienst `railtime-transit`:** Python 3.12 mit der Standardbibliothek `email` liest und schreibt MIME. Es ruft für den HTML-Teil `php transit-signatur.php` auf, damit die geprüfte Austauschlogik unverändert bleibt. Regeln:
   - Signierte (`multipart/signed`), verschlüsselte oder reine Textmails: unverändert weiter.
   - Vertrauenskontext oder zugelassene Header-Version fehlt, oder C-K7 findet keinen eindeutigen eigenen Block: unverändert weiter. Kein RT-Textmarker und keine HTML-ID erteilt eine Freigabe.
   - Austausch gelungen: HTML-Teil ersetzen, bei CID die Bilder als `multipart/related` anhängen, Kopfzeile `X-RailTime-Transit: v1` setzen, `pending` entfernen.
   - Jeder Fehler, Zeitüberschreitung über 10 s, Laufzeitfehler: Original Byte für Byte weiter, Fehler ins Log.
   - Klartextalternative unverändert lassen; keine generische Marker- oder Zitatbereinigung.
   - Versandfassung: U2e über den gemeinsamen Renderer nach dem obenstehenden Vertrag. Kein Umbau der Editorfassung wegen des erwarteten verkleinerten Handyempfangs. Der spätere App-Zugriff muss den authentifizierten Absender und die Header-Version serverseitig binden; kein öffentlicher Endpunkt mit frei wählbaren Profildaten.
   - Nach erfolgreichem Tausch: Kopie in „Gesendete Elemente“ über Graph (Ablauf Schritt 7), mit Zeitlimit 20 s und eigenem Logeintrag; Fehler dort halten die Zustellung nicht auf.
5. **Selbsttest ohne Exchange:** Mail mit Kopfzeile `pending` lokal per `sendmail` einspeisen, Rückgabe prüfen; eine Mail ohne Marker, eine signierte Mail, eine mit 8-MB-Anhang. Erwartung: nur die erste wird verändert, alle vier gehen über `transit-return` hinaus (dafür im Selbsttest `transit-return` auf `hold` setzen und die Warteschlange ansehen, nichts verlässt den Server).
6. **Unabhängig davon, aber vor dem Pilot:** DKIM in Exchange Online für rail-time.de einschalten (zwei CNAMEs `selector1`/`selector2` bei udag) und DMARC `p=none` mit Berichtsadresse setzen. Das verbessert die Zustellung aller Mails und ist Voraussetzung, damit Empfänger die zurückgespielten Mails nicht schlechter bewerten.

## Phase 2: Exchange Online (eigene Freigabe)
Ausführen mit Exchange-Admin-Rechten, Vorlage `code/transit-exchange-entwurf.ps1.txt`, vorher gegen die aktuelle Microsoft-Doku prüfen.

1. **Ausgehender Connector** `RailTime Signatur raus`: Typ Partner, Smarthost `plesk.follow-flow.de`, TLS mit Domainprüfung auf `plesk.follow-flow.de`, nur per Regel verwendbar. Ohne Regel ist er wirkungslos.
2. **Eingehender Connector** `RailTime Signatur zurück`: Typ OnPremises, TLS Pflicht, nur Zertifikat `plesk.follow-flow.de`. Dadurch gelten die Rückläufer als intern, Exchange hängt keinen „Extern“-Hinweis an und stellt an externe Empfänger zu.
3. **Regel** `RailTime Signatur Pilot`: authentifizierter Envelope-Absender aus der IT-Allowlist **und** genau ein gültiger `X-RailTime-Template`-Header; eine ausdrücklich gewählte Betreff-Schranke kann zusätzlich begrenzen. Eine fremd gesetzte Transit-Ergebniskopfzeile darf keine Schleifen-Ausnahme bewirken. TLS-/Tenant-/Connectorbindung nach C-K7 bleibt zwingend.
4. Keine Ausweitung über den IT-Piloten vor Abnahme von Block A und C sowie des späteren Transitpiloten.
5. Connector-Prüfung von Microsoft (Validate) mit einer Testadresse ausführen, Ergebnis festhalten.

## Phase 3: Abnahme (nur mit echten empfangenen Mails)
| Nr. | Test | Erwartung |
|---|---|---|
| 1 | Später ausdrücklich freigegebene neue Pilotmail ausschließlich an das IT-Testpostfach | U2e-Versandfassung, zwei `@media`, vollständige Metas, eindeutige eigene Struktur; Header und fehlende IDs protokolliert |
| 2 | Dieselbe Mail auf iPhone Mail und Outlook iOS | Handy-Format wie Vorlage (Logo rechts, Balken statt Karte, 24/7 nicht als Datum) |
| 3 | Empfang in Outlook Classic (Word), hell und dunkel | kein CSS als Text, Kartenecken ohne weiße Ecken |
| 4 | Gmail im Browser und Android | Spaltenumbruch, Bilder laden |
| 5 | Antwort und Weiterleitung auf eine Mail, die die Signatur schon enthält, aus neuem Outlook, Classic und Handy | nur die neue Signatur getauscht, zitierte Signatur unverändert lesbar, keine zweite Vorlage, Faden in Outlook zusammen (`In-Reply-To`), Kopie in „Gesendet“ im selben Faden |
| 5a | Antwort auf eine alte Mail mit Stilblöcken im Zitat (vor `71662e02`) | Zitat einschließlich Stilblöcken byteweise unverändert; Transit darf kein Aufräumen als Inhaltsschutz ausgeben |
| 6 | Weiterleitung mit Anhang 5 MB | Anhang unverändert, Prüfsumme gleich |
| 7 | Mail ohne Add-in-Signatur | unverändert, Kopfzeile `v1` trotzdem gesetzt |
| 8 | S/MIME-signierte Mail (falls vorhanden) | unverändert, Signatur gültig |
| 9 | Dienst gestoppt (`systemctl stop`), Postfix läuft | Mail kommt unverändert an (Postfix stellt den Filter zurück, dann Original) |
| 10 | Server nicht erreichbar (Port 25 kurz geschlossen) | Exchange hält die Mail, Zustellung nach Freigabe des Ports; Verzögerung messen |
| 11 | Kopfzeilen beim Empfänger | `Authentication-Results` mit `spf=pass`, `dkim=pass` (nach Phase 1.6), kein `X-RailTime-Transit: pending` mehr |
| 12 | Gesendete Elemente im neuen Outlook, Classic und auf dem iPhone | zeigen die Vorlage mit Handy-Format, genau eine Kopie, Datum und Empfänger unverändert, Editor-Fassung entfernt |
| 13 | Graph nicht erreichbar oder Recht fehlt | Mail wird trotzdem zugestellt, Editor-Fassung bleibt in „Gesendet“, Logeintrag |

Abnahmekriterium: Tests 1 bis 13 bestanden. Browser-Prüfungen zählen nicht als Abnahme.

## Phase 4: Abschalten und Rückbau
- **Sofort:** `Remove-TransportRule 'RailTime Signatur Pilot'`. Ab dann laufen alle Mails wieder direkt, Connectoren bleiben wirkungslos.
- **Vollständig:** Beide Connectoren entfernen, DNS-Eintrag und Postfix-Änderungen zurücknehmen, Dienst deinstallieren.
- Solange Mails in der Exchange-Warteschlange hängen (Test 10), erst Port öffnen, dann Regel entfernen, damit nichts verloren geht.

## Phase 5: Ausbau nach dem Pilot (nicht Teil der Freigabe)
- Absenderdaten aus der App: Endpunkt auf `app.rail-time.de`, der zum Absender die veröffentlichte Signatur liefert (Token-geschützt, nur vom Transit-Server erreichbar). Die Vorlage kommt dann aus dem Signatur-Editor statt aus einer Datei.
- Regel auf weitere Postfächer oder eine Gruppe ausdehnen, zuletzt auf alle.
- Add-in bleibt für Vorschau und Rückfall; im neuen Outlook eine Tabelle ohne feste Breiten, damit die Rückfallfassung wenigstens umbricht.
- Überwachung: Log des Dienstes, Zähler getauscht/unverändert/Fehler, Warnung bei Postfix-Warteschlange.

## Risiken
| Risiko | Folge | Maßnahme |
|---|---|---|
| Plesk überschreibt Postfix-Änderungen bei Updates | Filter fällt still aus, Mails gehen unverändert durch | Änderungen klein halten, nach Plesk-Updates prüfen, Zähler im Log |
| Server down | Pilot-Mails verzögert bis 24 h | nur ein Postfach, Abschaltung per Regel in Minuten |
| Microsoft-Adressbereiche ändern sich | Exchange wird abgewiesen, Mail verzögert | Liste automatisch aktualisieren, Warnung bei Abweisungen |
| Relay von fremden Tenants oder gefälschte Steuerheader | Fremde könnten über uns einliefern oder Austausch auslösen | Header/Microsoft-IP allein nie vertrauen; Connector/TLS, eigener Tenant und authentifizierte Pilot-Absenderbindung vor jeder Erkennung beweisen |
| Zertifikatserneuerung scheitert | Connector-TLS schlägt fehl | Let's Encrypt über Plesk automatisch, Ablaufwarnung |
| Kopie in „Gesendete Elemente“ scheitert oder verdoppelt | Editor-Fassung bleibt, oder zwei Einträge | Kopie nur nach erfolgreichem Tausch, Original per `Message-ID` suchen, erst nach erfolgreicher Kopie löschen, Zeitlimit, Log |
| Graph-Anwendungsrecht zu weit | Zugriff auf alle Postfächer | Application Access Policy auf `it-technik@`, Geheimnis nur auf dem Server, Ausbau je Postfach erweitern |
| Zitat in Antworten mit alten Stilblöcken | CSS als Text in Classic | Keine Transit-Bereinigung; Zitatgrenze und Byteerhalt nachweisen, Darstellungsprobleme separat behandeln |

## Aufwand (Schätzung)
- Phase 1: 2 bis 3 Tage Codex (Dienst, MIME, Selbsttest, Graph-Kopie), 30 Minuten Admin (App-Registrierung, Access Policy)
- Phase 2: 1 Stunde Exchange-Admin
- Phase 3: 1 Tag mit Geräten
- Phase 1.6 (DKIM/DMARC): 30 Minuten plus DNS-Laufzeit

## Nächster Schritt
C-K7 zunächst als Plan- und isolierten Testnachweis prüfen. Danach stehen die tatsächliche Compose-Rücklesefixture und die offenen nativen A/C-Abnahmen an. Erst im später ausdrücklich freigegebenen Block E folgen ein abgesicherter Transportentwurf und der separate Sent-Items-Machbarkeitsnachweis. Keine der hier aufgeführten Connector-, Postfix-, DNS- oder Graph-Skizzen wird durch C-K7 ausgeführt.
