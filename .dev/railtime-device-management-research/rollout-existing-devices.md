# Rollout der bereits ausgegebenen Geräte

Die Geräte müssen nicht deutschlandweit ins Hauptlager zurückgesendet werden.
Sie werden in Wellen per persönlicher RailTime-Einladung nachregistriert.

## Vorbereitung

1. Bestand per CSV oder manuell mit Asset, Seriennummer, Plattform, aktuellem
   Mitarbeiter und deklariertem Standort importieren.
2. Dubletten/unklare Seriennummern in eine Klärungswarteschlange legen.
3. Pro Gerät passenden Enrollment-Modus bestimmen: Agent, Arbeitsprofil,
   profilbasiertes Apple Enrollment oder späterer Reset/ADE.
4. Einmal-Link erzeugen. RailTime speichert nur den Hash, Ablauf und Bindung an
   Mitarbeiter und Gerät.

## Mitarbeiterablauf

1. Mitarbeiter erhält eine RailTime-E-Mail und meldet sich normal an.
2. „Meine Geräte einrichten“ zeigt ausschließlich seine zugeordneten Geräte.
3. Laptop: signierten Verwaltungs-/Remote-Agent laden und starten.
4. Android: Arbeitsprofil/Community-Pilot per Link oder QR; volle
   Firmenverwaltung erst in einem geplanten Resetfenster.
5. iPhone/iPad: Profil-/Account-driven Enrollment mit Zustimmung; Supervision
   und nicht entfernbares ADE erst bei geplanter Neueinrichtung.
6. Mitarbeiter bestätigt den sichtbaren Verwaltungsumfang und meldet sich
   einmal per OAuth/MFA an.
7. Providerbelege aktualisieren Readiness; der Mitarbeiter kann die Einrichtung
   nicht selbst fälschlich auf `bereit` setzen.

## Wellen

- Welle 0: IT-Labor, je Plattform und Modus mindestens ein Gerät.
- Welle 1: zehn freiwillige Mitarbeitende aus unterschiedlichen Regionen.
- Welle 2: Windows/macOS/Linux-Bestand, weil Agent-Rollout ohne Reset möglich.
- Welle 3: mobile Bestandsgeräte im eingeschränkten Modus.
- Welle 4: Full-Management-Reset bei Austausch, Reparatur, Rückgabe oder
  geplantem Vor-Ort-Termin.

## Sonderfälle

- Gerät offline: Auftrag bleibt sichtbar `wartet auf Gerät`, nicht erfolgreich.
- Falsche Person: Enrollment lässt sich nicht einlösen; Admin korrigiert die
  Zuweisung und erstellt einen neuen Link.
- Token abgelaufen/benutzt: neuer Link, alter Hash bleibt widerrufen im Audit.
- Persönliches/BYOD-Gerät: nur Arbeitsprofil/User Enrollment und minimale
  Geschäftsdaten; kein stiller Full-Device-Owner.
- Verlust: sofort Support-/Security-Flow, aber Lock/Wipe nur bei belegter
  Providerfähigkeit und Wipe mit Vier-Augen-Freigabe.

