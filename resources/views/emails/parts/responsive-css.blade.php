{{--
    EINZIGE Quelle der Umbruchregeln fuer Vorlage UND Signatur.

    Genutzt von:
      - resources/mail-templates/email-master.html        ({{RESPONSIVE_CSS}})
      - resources/mail-templates/signature-*-master.html  ({{RESPONSIVE_CSS}})
      - resources/views/vendor/mail/html/layout.blade.php (@include)

    WARUM ZENTRAL: Vorher stand derselbe Block viermal im Projekt und war
    bereits auseinandergelaufen — die Vorlage brach bei 680 px um, die
    Signatur bei 620 px. In einer Mail, die beides enthaelt, sprang das
    Layout dadurch an zwei verschiedenen Breiten.

    DREI STUFEN statt einer:
      <= 1000 px  Tablet quer  — enger setzen, Marke verkleinern, zweispaltig
                                 bleiben. Ohne diese Stufe stand die Signatur
                                 auf einem 700-px-Schirm weiter im vollen
                                 Breitlayout (zwei halbe Spalten plus 24 px
                                 Steg) und war deshalb gequetscht.
      <=  860 px  Tablet hoch  — stapeln: Firmenlogo, Person und Firmendaten,
                                 Karten der Vorlage einspaltig.
      <=  480 px  Telefon      — Innenabstaende weiter zuruecknehmen.

    GESTAPELT BLEIBT DIE QUELLREIHENFOLGE ERHALTEN: erst die einmalige
    Wortmarke, dann die Person, dann die einmaligen Firmendaten. Der
    zweizeilige RTL-Tabellenaufbau stellt auf Desktop trotzdem Person links
    und Firma rechts dar. Dadurch braucht Mobil keine versteckte Kopie, die
    ein Reply-/Forward-Client versehentlich zusaetzlich anzeigen koennte.

    @param string $border  Farbwert der Trennlinie (SIGNATURE_BORDER)
--}}
@php
    $border = $border ?? '#e6e8ec';
@endphp
/* RT_SERVER_SIGNATURE_RUNTIME_START
   Stabiler Einhaengepunkt fuer freigegebenes Signatur-CSS. Der echte
   IMG-Layer steht im normalen Mailfluss vor den Daten. Layer und Inhalt
   besitzen je Breakpoint dieselbe feste Pixelhoehe; die identische negative
   Pixel-Margin legt beide Flaechen ohne Viewport-Berechnung uebereinander.
   Eine Presentation-Tabellenzelle richtet das Zug-IMG unten aus. */
.rt-sign-stage {
  position: relative !important;
  height: 200px !important;
  max-height: 200px !important;
  overflow: hidden !important;
}
.rt-sign-train-layer {
  display: block !important;
  width: 100% !important;
  height: 200px !important;
  max-height: 200px !important;
  max-width: 1815px !important;
  margin-top: 0 !important;
  margin-bottom: -200px !important;
  overflow: hidden !important;
  font-size: 0 !important;
  line-height: 0 !important;
  text-align: left !important;
}
.rt-sign-train-frame,
.rt-sign-content-frame {
  width: 100% !important;
  height: 200px !important;
  border-collapse: collapse !important;
}
.rt-sign-train-slot {
  height: 200px !important;
  padding: 0 !important;
  text-align: left !important;
  vertical-align: bottom !important;
  font-size: 0 !important;
  line-height: 0 !important;
}
/* V11 bis V13 behalten fuer vollstaendige Personensignaturen eine sichere Reserve.
   Systemmails erhalten nach dem Entfernen leerer Kontaktzeilen serverseitig
   das kompakte Dichteprofil. Zug und Inhalt teilen weiterhin exakt dieselbe
   feste Pixelhoehe. */
tr[data-rt-artifact-version="v11"] .rt-sign-stage,
tr[data-rt-artifact-version="v12"] .rt-sign-stage,
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v13"] .rt-sign-stage,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer {
  height: 190px !important;
  max-height: 190px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer {
  margin-bottom: -190px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v11"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v11"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v12"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v12"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v12"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v13"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v13"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v13"] .rt-sign-content-frame { height: 190px !important; }
/* V14 zieht die Breitbuehne bis knapp an die 171-px-Zugdatei heran. Zug-Layer
   und Inhalt bleiben durch dieselbe feste Hoehe und negative Pixel-Margin
   deckungsgleich, statt zwei aufeinanderfolgende Bereiche zu bilden. */
tr[data-rt-artifact-version="v14"] .rt-sign-stage,
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer {
  height: 175px !important;
  max-height: 175px !important;
}
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer {
  margin-bottom: -175px !important;
}
tr[data-rt-artifact-version="v14"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v14"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v14"] .rt-sign-content-frame { height: 175px !important; }
/* V15 behaelt seine deckungsgleiche 175-px-Innengeometrie. V16 reserviert
   200 px fuer vollstaendige Kontaktdaten; so endet die Buehne weiterhin exakt
   an der Rechtstext-Zeile. Beide Aussenbuehnen bleiben fail-open. */
tr[data-rt-artifact-version="v15"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 175px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v15"] .rt-sign-train-layer {
  position: relative !important;
  z-index: 0 !important;
  height: 175px !important;
  max-height: 175px !important;
  margin-bottom: -175px !important;
}
tr[data-rt-artifact-version="v15"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v15"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v15"] .rt-sign-content-frame { height: 175px !important; }
tr[data-rt-artifact-version="v15"] .rt-sign-content-frame {
  position: relative !important;
  z-index: 1 !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 200px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-layer {
  position: relative !important;
  z-index: 0 !important;
  height: 200px !important;
  max-height: 200px !important;
  margin-bottom: -200px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v16"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v16"] .rt-sign-content-frame { height: 200px !important; }
tr[data-rt-artifact-version="v16"] .rt-sign-content-frame {
  position: relative !important;
  z-index: 1 !important;
}
/* V17/V18/V20 behalten die V16-Buehne, trennen jedoch das flexible Haupt-GIF
   vom exakt proportionalen Outlook-Standbild. V20 verwendet bewusst wieder
   exakt diese V18-Geometrie und lediglich die komprimierten Medien. */
tr[data-rt-artifact-version="v17"] .rt-sign-stage,
tr[data-rt-artifact-version="v18"] .rt-sign-stage,
tr[data-rt-artifact-version="v20"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 200px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v18"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v20"] .rt-sign-train-layer {
  position: relative !important;
  z-index: 0 !important;
  height: 200px !important;
  max-height: 200px !important;
  margin-bottom: -200px !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v17"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v17"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v18"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v20"] .rt-sign-content-frame { height: 200px !important; }
tr[data-rt-artifact-version="v17"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-content-frame {
  position: relative !important;
  z-index: 1 !important;
}
/* V19 benoetigt keine negative Margin mehr. Der absolut verankerte Layer
   folgt der realen Buehnenhoehe; sein 61-px-Inline-Fallback bleibt bei einer
   Weiterleitungs-Rekonstruktion proportional und erzeugt keinen 200-px-Spalt. */
tr[data-rt-artifact-version="v19"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 200px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v19"] .rt-sign-train-layer {
  position: absolute !important;
  z-index: 0 !important;
  left: 0 !important;
  right: 0 !important;
  top: 0 !important;
  bottom: 0 !important;
  height: auto !important;
  max-height: none !important;
  margin: 0 !important;
}
tr[data-rt-artifact-version="v19"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v19"] .rt-sign-train-slot { height: 100% !important; }
tr[data-rt-artifact-version="v19"] .rt-sign-content-frame {
  position: relative !important;
  z-index: 1 !important;
  height: 200px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-stage,
tr[data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 145px !important;
  max-height: 145px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-train-layer {
  margin-bottom: -145px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-signature-density="compact"] .rt-sign-content-frame { height: 145px !important; }
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 145px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 145px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 145px !important;
  overflow: visible !important;
}
/* Bei der maximalen 1815-px-Breite waere das 108,67-Prozent-Motiv hoeher als
   die kompakte 145-px-Buehne und wuerde unten beschnitten. V14 begrenzt nur
   dieses Desktop-Systemprofil auf 94 Prozent; die spaeteren Mobilregeln sind
   spezifischer und vergroessern es dort weiterhin auf 150 beziehungsweise 175 Prozent. */
tr[data-rt-artifact-version="v14"][data-rt-signature-density="compact"] .rt-sign-train,
tr[data-rt-artifact-version="v14"][data-rt-signature-density="compact"] .rt-sign-train-mso,
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train,
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-mso {
  width: 94% !important;
  max-width: none !important;
  margin-left: 0 !important;
}
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train,
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-mso {
  width: 94% !important;
  max-width: none !important;
  margin-left: 0 !important;
}
/* Das V16-Motiv besitzt bis zum letzten Pixel Inhalt. Blockdarstellung und
   bottom-valign verhindern deshalb eine zusaetzliche Inline-Grundlinie direkt
   vor der grauen Rechtstext-Zeile. */
tr[data-rt-artifact-version="v16"] .rt-sign-train-slot {
  padding-bottom: 0 !important;
  vertical-align: bottom !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train,
tr[data-rt-artifact-version="v16"] .rt-sign-train-mso {
  display: block !important;
  margin-bottom: 0 !important;
  vertical-align: bottom !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v18"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v19"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v20"] .rt-sign-train-slot {
  padding-bottom: 0 !important;
  vertical-align: bottom !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train,
tr[data-rt-artifact-version="v18"] .rt-sign-train,
tr[data-rt-artifact-version="v19"] .rt-sign-train,
tr[data-rt-artifact-version="v20"] .rt-sign-train {
  display: block !important;
  height: auto !important;
  margin-bottom: 0 !important;
  vertical-align: bottom !important;
}
/* Im vollwertigen Mail-/Editor-CSS behaelt V19 die visuelle Zugbreite der
   V18-Fassung. Nur der CSS-lose Weiterleitungs-Fallback bleibt bewusst bei
   proportionalen 720x61 Pixeln und kann deshalb keinen grossen Leerblock
   erzeugen. */
tr[data-rt-artifact-version="v19"] .rt-sign-train {
  width: 108.67% !important;
  max-width: none !important;
  margin-left: 0 !important;
}
/* Outlook-Desktop erhaelt fuer V17 bis V20 ein separates, unverzerrbares Standbild. */
tr[data-rt-artifact-version="v17"] .rt-sign-train-mso,
tr[data-rt-artifact-version="v18"] .rt-sign-train-mso,
tr[data-rt-artifact-version="v19"] .rt-sign-train-mso,
tr[data-rt-artifact-version="v20"] .rt-sign-train-mso {
  display: block !important;
  width: 720px !important;
  max-width: 720px !important;
  height: 61px !important;
  margin: 0 !important;
  vertical-align: bottom !important;
}
.rt-sign-train,
.rt-sign-train-mso {
  position: static !important;
  left: auto !important;
  right: auto !important;
  bottom: auto !important;
  display: inline-block !important;
  max-width: none !important;
  height: auto !important;
  margin-top: 0 !important;
  margin-right: 0 !important;
  margin-bottom: 0 !important;
  vertical-align: bottom !important;
  z-index: 0 !important;
}
@keyframes rt-train-idle-reveal {
  0% { opacity: 0; visibility: hidden; }
  100% { opacity: 1; visibility: visible; }
}
.rt-train-idle-overlay {
  position: absolute !important;
  left: 0 !important;
  right: auto !important;
  top: auto !important;
  bottom: 0 !important;
  display: block !important;
  width: 100% !important;
  max-width: none !important;
  height: 0 !important;
  max-height: 0 !important;
  margin: 0 !important;
  overflow: hidden !important;
  z-index: 1 !important;
  font-size: 0 !important;
  line-height: 0 !important;
  opacity: 0;
  visibility: hidden;
}
.rt-train-idle-image {
  position: absolute !important;
  left: 0 !important;
  right: auto !important;
  bottom: 0 !important;
  display: inline-block !important;
  max-width: none !important;
  height: auto !important;
  margin-top: 0 !important;
  margin-right: 0 !important;
  margin-bottom: 0 !important;
  vertical-align: bottom !important;
  z-index: 1 !important;
}
.rt-sign-train-layer[data-rt-layer-align="left"] { margin-left: 0 !important; margin-right: auto !important; }
.rt-sign-train-layer[data-rt-layer-align="center"] { margin-left: auto !important; margin-right: auto !important; }
.rt-sign-train-layer[data-rt-layer-align="right"] { margin-left: auto !important; margin-right: 0 !important; }
.rt-sign-train-layer[data-rt-layer-size="100"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-size="100"] .rt-train-idle-image { width: 100% !important; max-width: none !important; }
.rt-sign-train-layer[data-rt-layer-size="125"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-size="125"] .rt-train-idle-image { width: 125% !important; max-width: none !important; }
.rt-sign-train-layer[data-rt-layer-size="150"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-size="150"] .rt-train-idle-image { width: 150% !important; max-width: none !important; }
.rt-sign-train-layer[data-rt-layer-size="200"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-size="200"] .rt-train-idle-image { width: 200% !important; max-width: none !important; }
.rt-sign-train-layer[data-rt-layer-align="left"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="left"] .rt-train-idle-image,
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="100"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="100"] .rt-train-idle-image,
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="100"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="100"] .rt-train-idle-image { margin-left: 0 !important; }
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="125"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="125"] .rt-train-idle-image { margin-left: -12.5% !important; }
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="150"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="150"] .rt-train-idle-image { margin-left: -25% !important; }
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="200"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="center"][data-rt-layer-size="200"] .rt-train-idle-image { margin-left: -50% !important; }
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="125"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="125"] .rt-train-idle-image { margin-left: -25% !important; }
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="150"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="150"] .rt-train-idle-image { margin-left: -50% !important; }
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="200"] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-align="right"][data-rt-layer-size="200"] .rt-train-idle-image { margin-left: -100% !important; }
@supports (animation-name: rt-train-idle-reveal) {
  .rt-train-idle-overlay {
    overflow: visible !important;
    animation-name: rt-train-idle-reveal;
    animation-duration: 1ms;
    animation-timing-function: step-start;
    animation-delay: 13s;
    animation-iteration-count: 1;
    animation-fill-mode: forwards;
  }
}
@media (prefers-reduced-motion: reduce) {
  .rt-train-idle-overlay {
    animation: none !important;
    overflow: hidden !important;
    opacity: 0 !important;
    visibility: hidden !important;
  }
}

/* Hauptzug, Idle-Rauch und Outlook-Standbild bleiben echte IMG im selben
   Flow-Layer. Die negative Layer-Margin ueberlappt den danach folgenden
   Inhalt in allen Ausgabepfaden. */
/* ---- Tablet quer: enger setzen, aber zweispaltig bleiben ---- */
@media only screen and (max-width: 1000px) {
.rt-pad { padding-left: 30px !important; padding-right: 30px !important; }
.rt-title { font-size: 30px !important; line-height: 35px !important; }
.rt-sign-logo, .rt-sign-company { padding-left: 18px !important; }
{{-- Nur die Wortmarke, nicht jedes Bild der Spalte: seit die Firmenliste
     dort steht, traf ein `.rt-sign-logo img` auch die Kontaktsymbole und
     zog sie auf Logobreite auseinander. --}}
img.rt-logo { width: 180px !important; }
.rt-contact img { width: 22px !important; height: 22px !important; }
.rt-sign-identity { padding-right: 18px !important; }
.rt-sign-name { font-size: 22px !important; line-height: 26px !important; }
.rt-contact td.rt-contact-text { font-size: 11px !important; line-height: 17px !important; }
{{-- BEIDE Kartenzellen, nicht nur die linke: .rt-card-cell steht allein an
     der Zelle mit der Trennlinie. Ein frueher hier stehendes td.rt-card traf
     ueberhaupt kein Element, die rechte Karte behielt ihre 22 px. --}}
tr.rt-stack > td.rt-card-cell, tr.rt-stack > td.rt-card-cell + td { padding-left: 16px !important; padding-right: 16px !important; }
}

/* ---- Tablet hoch und kleiner: stapeln ---- */
@media only screen and (max-width: 860px) {
.rt-sign-stage {
  height: 296px !important;
  max-height: 296px !important;
}
.rt-sign-train-layer {
  height: 296px !important;
  max-height: 296px !important;
  margin-bottom: -296px !important;
}
.rt-sign-train-frame,
.rt-sign-train-slot,
.rt-sign-content-frame { height: 296px !important; }
.rt-pad { padding-left: 24px !important; padding-right: 24px !important; }
.rt-title { font-size: 27px !important; line-height: 32px !important; }
{{-- Nur DIREKTE Zellen der markierten Zeile umbrechen: ein Nachfahren-
     Selektor wuerde auch die verschachtelte Kontakttabelle zerlegen und
     Symbol und Text untereinander werfen. --}}
tr.rt-stack > td { box-sizing: border-box !important; display: block !important; width: 100% !important; }
tr.rt-stack > td + td { padding-top: 12px !important; }
.rt-sign-layout, .rt-sign-layout > tbody,
.rt-sign-top-row, .rt-sign-company-row {
  display: block !important;
  width: 100% !important;
}
.rt-sign-company {
  box-sizing: border-box !important;
  display: block !important;
  width: 100% !important;
}
/* Gestapelte Kartenzellen brauchen keine Trennlinie zur Seite mehr. */
.rt-card-cell { border-right: 0 !important; }
/* Schutzregel: Symbol und Kontaktzeile bleiben nebeneinander, auch wenn
   ein Client den Kindselektor oben zu einem Nachfahren umbaut. */
.rt-contact { width: auto !important; }
.rt-contact td.rt-contact-icon, .rt-contact td.rt-contact-text { display: table-cell !important; box-sizing: content-box !important; padding-top: 0 !important; }
.rt-contact td.rt-contact-icon { width: 22px !important; }
.rt-contact td.rt-contact-text { width: auto !important; font-size: 13px !important; line-height: 19px !important; }
.rt-sign-name { font-size: 21px !important; line-height: 25px !important; }
/* SIGNATUR OHNE RESPONSIVE DOM-DOPPELUNGEN. Logo, Person und Firmendaten
   werden in ihrer Quellreihenfolge gestapelt. Es gibt keine versteckte
   Mobilfassung, die ein Reply-/Forward-Client zusaetzlich einblenden kann. */
.rt-sign-identity { padding: 14px 0 0 !important; }
.rt-sign-logo {
  padding: 0 0 14px !important;
  border-left: 0 !important;
  border-bottom: 1px solid {{ $border }} !important;
  text-align: left !important;
}
.rt-sign-company {
  padding: 12px 0 0 !important;
  border-left: 0 !important;
  text-align: left !important;
}
img.rt-logo {
  width: 150px !important;
  margin-left: 0 !important;
}
.rt-person-kopf, .rt-sign-identity .rt-contact,
.rt-company-contact {
  display: block !important;
  width: auto !important;
  margin-left: 0 !important;
  margin-right: 0 !important;
}
/* Die Personenkontakte bleiben eng unter der Funktion. Die Firmendaten
   folgen nach der einmaligen Wortmarke in derselben gestapelten Zelle. */
.rt-sign-identity .rt-contact { margin-top: 10px !important; }
/* Es gibt nur noch EINEN Firmenkontaktblock. Mobil wird genau dieselbe
   Tabelle linksbuendig und ueber die verfuegbare Spaltenbreite gesetzt;
   keine versteckte Kopie kann beim Antworten oder Weiterleiten auftauchen. */
.rt-company-contact {
  float: none !important;
  display: table !important;
  width: 100% !important;
  margin-top: 14px !important;
  margin-left: 0 !important;
  margin-right: auto !important;
}
.rt-company-contact td.rt-company-contact-text { text-align: left !important; }
/* ---- Gestapelt wird ALLES eine Nummer kleiner ----------------------
   Auf dem Telefon steht die Signatur unter einer Nachricht, nicht als
   Aushaengeschild. Sie soll lesbar sein und wenig Hoehe kosten — deshalb
   kleinere Schrift, kleinere Symbole, engere Zeilen. Die Werte greifen
   HIER und nicht in der 1000er-Stufe, damit das Breitlayout unberuehrt
   bleibt. */
.rt-contact td.rt-contact-text { font-size: 11px !important; line-height: 15px !important; }
/* Symbole von 22 auf 16 px. Beide Angaben noetig: die Zellbreite haelt die
   Spalte schmal, die Bildgroesse das Symbol selbst — das width-Attribut am
   img wiegt sonst schwerer als die Zelle. */
.rt-contact img { width: 16px !important; height: 16px !important; }
.rt-contact td.rt-contact-icon { width: 16px !important; }
.rt-contact td.rt-contact-text { padding-left: 7px !important; padding-right: 0 !important; }
.rt-company-contact td.rt-company-contact-text { padding-left: 7px !important; }
/* Zeilen enger stellen: der Abstand nach unten war fuer 22-px-Symbole
   bemessen. */
.rt-contact td.rt-contact-icon, .rt-contact td.rt-contact-text { padding-bottom: 4px !important; }
.rt-sign-name { font-size: 18px !important; line-height: 22px !important; }
img.rt-logo { width: 150px !important; }
/* Die Firmenliste sitzt gestapelt direkt unter der Wortmarke — der
   Vorsprung, den sie im Breitlayout ausgleicht, faellt hier weg. */

/* Die bildfreie, transparente Grundebene bleibt mobil unveraendert. Raster
   und grosses RT-Wasserzeichen gehoeren nicht mehr zur Signatur. */
.rt-sign-cell {
  background-position: center center !important;
  background-size: 100% 100% !important;
}
.rt-sign-train-layer {
  width: 100% !important;
  max-width: 1815px !important;
  margin-top: 0 !important;
}
/* Der fliessende Clipping-Viewport bleibt vollbreit. Seine vertikale
   Geometrie ist direkt auf 296px festgelegt. Das Standardmotiv wird auf
   Tablets bereits vergroessert; nur der horizontale Bildausschnitt wird
   weiterhin ueber die erlaubten Presets gewaehlt. */
.rt-sign-train-layer[data-rt-layer-mobile="left"][data-rt-layer-size] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-mobile="left"][data-rt-layer-size] .rt-train-idle-image { width: 200% !important; max-width: none !important; margin-left: 0 !important; }
.rt-sign-train-layer[data-rt-layer-mobile="center"][data-rt-layer-size] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-mobile="center"][data-rt-layer-size] .rt-train-idle-image { width: 200% !important; max-width: none !important; margin-left: -50% !important; }
.rt-sign-train-layer[data-rt-layer-mobile="right"][data-rt-layer-size] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-mobile="right"][data-rt-layer-size] .rt-train-idle-image { width: 200% !important; max-width: none !important; margin-left: -100% !important; }
.rt-sign-train-layer[data-rt-layer-mobile="train"][data-rt-layer-size] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-mobile="train"][data-rt-layer-size] .rt-train-idle-image { width: 150% !important; max-width: none !important; margin-left: 0 !important; }
/* V8/V9: Das sichtbare Ende des auf 60 % animierten Motivs steht bei 65 %
   der Signaturbreite (60 % von 150 % minus 25 % Versatz). */
.rt-sign-train-layer[data-rt-layer-mobile="stop65"][data-rt-layer-size] .rt-sign-train { width: 150% !important; max-width: none !important; margin-left: -25% !important; }
/* V16 stoppt sichtbar bei rund 60 Prozent. Selbst wenn ein mobiler Outlook-
   Renderer den negativen Versatz ignoriert, endet die Lok bei rund 96 Prozent
   und bleibt damit innerhalb der Signaturbreite. */
.rt-sign-train-layer[data-rt-layer-mobile="stop60"][data-rt-layer-size] .rt-sign-train {
  width: 160% !important;
  max-width: none !important;
  margin-left: -36% !important;
}
/* V10/V11: Das Motiv beginnt ohne negativen Versatz exakt an der linken
   Signaturkante. 108,67 % bilden den sichtbaren Motivabschluss bei ca. 65 %
   ab, ohne dass die Lok rechts aus dem Ausschnitt fahren kann. */
tr[data-rt-artifact-version="v10"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train,
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train {
  width: 108.67% !important;
  max-width: none !important;
  margin-left: 0 !important;
}
/* Vollstaendige V11-bis-V14-Kontakte behalten bei gestapeltem Layout 296 px. Das
   kompakte Systemprofil benoetigt nach der Kontaktbereinigung nur 215 px. */
tr[data-rt-artifact-version="v11"] .rt-sign-stage,
tr[data-rt-artifact-version="v12"] .rt-sign-stage,
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v13"] .rt-sign-stage,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v14"] .rt-sign-stage,
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer {
  height: 296px !important;
  max-height: 296px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer {
  margin-bottom: -296px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v11"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v11"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v12"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v12"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v12"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v13"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v13"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v13"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v14"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v14"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v14"] .rt-sign-content-frame { height: 296px !important; }
tr[data-rt-signature-density="compact"] .rt-sign-stage,
tr[data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 215px !important;
  max-height: 215px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-train-layer {
  margin-bottom: -215px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-signature-density="compact"] .rt-sign-content-frame { height: 215px !important; }
tr[data-rt-artifact-version="v15"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 296px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v15"] .rt-sign-train-layer {
  height: 296px !important;
  max-height: 296px !important;
  margin-bottom: -296px !important;
}
tr[data-rt-artifact-version="v15"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v15"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v15"] .rt-sign-content-frame { height: 296px !important; }
tr[data-rt-artifact-version="v16"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 304px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-layer {
  height: 304px !important;
  max-height: 304px !important;
  margin-bottom: -304px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v16"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v16"] .rt-sign-content-frame { height: 304px !important; }
tr[data-rt-artifact-version="v17"] .rt-sign-stage,
tr[data-rt-artifact-version="v18"] .rt-sign-stage,
tr[data-rt-artifact-version="v20"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 304px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v18"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v20"] .rt-sign-train-layer {
  height: 304px !important;
  max-height: 304px !important;
  margin-bottom: -304px !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v17"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v17"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v18"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v20"] .rt-sign-content-frame { height: 304px !important; }
tr[data-rt-artifact-version="v19"] .rt-sign-stage {
  min-height: 304px !important;
}
tr[data-rt-artifact-version="v19"] .rt-sign-content-frame { height: 304px !important; }
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 215px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 215px !important;
  max-height: 215px !important;
  margin-bottom: -215px !important;
}
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-content-frame { height: 215px !important; }
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 215px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 215px !important;
  max-height: 215px !important;
  margin-bottom: -215px !important;
}
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-content-frame { height: 215px !important; }
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 215px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 215px !important;
  max-height: 215px !important;
  margin-bottom: -215px !important;
}
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-content-frame { height: 215px !important; }
}

/* ---- Telefon: Innenabstaende weiter zuruecknehmen ---- */
@media only screen and (max-width: 480px) {
.rt-sign-stage {
  height: 296px !important;
  max-height: 296px !important;
}
.rt-sign-train-layer {
  height: 296px !important;
  max-height: 296px !important;
  margin-bottom: -296px !important;
}
.rt-sign-train-frame,
.rt-sign-train-slot,
.rt-sign-content-frame { height: 296px !important; }
/* Nur die als V8 bis V10 gekennzeichneten Importlayouts werden auf dem Telefon kompakter.
   Aeltere importierte Signaturen behalten ihren bisherigen 296-px-Vertrag. */
tr[data-rt-artifact-version="v8"] .rt-sign-stage,
tr[data-rt-artifact-version="v9"] .rt-sign-stage,
tr[data-rt-artifact-version="v10"] .rt-sign-stage {
  height: 280px !important;
  max-height: 280px !important;
}
tr[data-rt-artifact-version="v8"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v9"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v10"] .rt-sign-train-layer {
  height: 280px !important;
  max-height: 280px !important;
  margin-bottom: -280px !important;
}
tr[data-rt-artifact-version="v8"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v8"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v8"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v9"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v9"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v9"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v10"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v10"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v10"] .rt-sign-content-frame { height: 280px !important; }
/* V10 zieht Zug und Kontaktdaten zehn Pixel naeher zusammen. Die separat
   gesetzten Rahmenhoehen halten Editor, Vorschau und Mailfluss identisch. */
tr[data-rt-artifact-version="v10"] .rt-sign-stage,
tr[data-rt-artifact-version="v10"] .rt-sign-train-layer {
  height: 270px !important;
  max-height: 270px !important;
}
tr[data-rt-artifact-version="v10"] .rt-sign-train-layer {
  margin-bottom: -270px !important;
}
tr[data-rt-artifact-version="v10"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v10"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v10"] .rt-sign-content-frame { height: 270px !important; }
/* V11 bis V14: 264 px sichern die vollstaendige Personensignatur. Das serverseitig
   markierte Systemprofil reduziert die leere Telefonreserve auf 190 px. */
tr[data-rt-artifact-version="v11"] .rt-sign-stage,
tr[data-rt-artifact-version="v12"] .rt-sign-stage,
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v13"] .rt-sign-stage,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v14"] .rt-sign-stage,
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer {
  height: 264px !important;
  max-height: 264px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer {
  margin-bottom: -264px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v11"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v11"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v12"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v12"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v12"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v13"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v13"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v13"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v14"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v14"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v14"] .rt-sign-content-frame { height: 264px !important; }
tr[data-rt-signature-density="compact"] .rt-sign-stage,
tr[data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 190px !important;
  max-height: 190px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-train-layer {
  margin-bottom: -190px !important;
}
tr[data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-signature-density="compact"] .rt-sign-content-frame { height: 190px !important; }
tr[data-rt-artifact-version="v15"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 264px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v15"] .rt-sign-train-layer {
  height: 264px !important;
  max-height: 264px !important;
  margin-bottom: -264px !important;
}
tr[data-rt-artifact-version="v15"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v15"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v15"] .rt-sign-content-frame { height: 264px !important; }
tr[data-rt-artifact-version="v16"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 272px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-layer {
  height: 272px !important;
  max-height: 272px !important;
  margin-bottom: -272px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v16"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v16"] .rt-sign-content-frame { height: 272px !important; }
tr[data-rt-artifact-version="v17"] .rt-sign-stage,
tr[data-rt-artifact-version="v18"] .rt-sign-stage,
tr[data-rt-artifact-version="v20"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 272px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v18"] .rt-sign-train-layer,
tr[data-rt-artifact-version="v20"] .rt-sign-train-layer {
  height: 272px !important;
  max-height: 272px !important;
  margin-bottom: -272px !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v17"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v17"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v18"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v18"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v20"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v20"] .rt-sign-content-frame { height: 272px !important; }
tr[data-rt-artifact-version="v19"] .rt-sign-stage {
  min-height: 272px !important;
}
tr[data-rt-artifact-version="v19"] .rt-sign-content-frame { height: 272px !important; }
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 190px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 190px !important;
  max-height: 190px !important;
  margin-bottom: -190px !important;
}
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v15"][data-rt-signature-density="compact"] .rt-sign-content-frame { height: 190px !important; }
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 190px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 190px !important;
  max-height: 190px !important;
  margin-bottom: -190px !important;
}
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v16"][data-rt-signature-density="compact"] .rt-sign-content-frame { height: 190px !important; }
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-stage {
  height: auto !important;
  max-height: none !important;
  min-height: 190px !important;
  overflow: visible !important;
}
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-train-layer {
  height: 190px !important;
  max-height: 190px !important;
  margin-bottom: -190px !important;
}
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-train-slot,
tr[data-rt-artifact-version="v17"][data-rt-signature-density="compact"] .rt-sign-content-frame { height: 190px !important; }
.rt-pad { padding-left: 18px !important; padding-right: 18px !important; }
.rt-title { font-size: 24px !important; line-height: 29px !important; }
.rt-sign-name { font-size: 17px !important; line-height: 21px !important; }
img.rt-logo { width: 138px !important; }
/* V19 verwendet dieselbe 150-px-Wortmarke im mobilen Original und im
   Inline-Fallback. Entfernt iPhone Mail beim Weiterleiten das Head-CSS,
   springt das Logo deshalb nicht mehr von 138 auf 200 Pixel. */
tr[data-rt-artifact-version="v19"] img.rt-logo {
  width: 150px !important;
  max-width: 150px !important;
  height: auto !important;
}

/* Auf Telefonbreite ist der Zug 75 Prozent groesser als der Viewport. Der
   gemessene Versatz haelt die Lok vollstaendig innerhalb der rechten Kante;
   nur die langen Wagen bleiben links bewusst angeschnitten. */
.rt-sign-train-layer[data-rt-layer-mobile="train"][data-rt-layer-size] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-mobile="train"][data-rt-layer-size] .rt-train-idle-image {
  width: 175% !important;
  max-width: none !important;
  margin-left: -8% !important;
}
/* V8/V9: 60 % der 175-prozentigen Bildbreite abzueglich 40 % Versatz
   positioniert das Animationsende bei 65 % der mobilen Signaturbreite. */
.rt-sign-train-layer[data-rt-layer-mobile="stop65"][data-rt-layer-size] .rt-sign-train {
  width: 175% !important;
  max-width: none !important;
  margin-left: -40% !important;
}
tr[data-rt-artifact-version="v10"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train,
tr[data-rt-artifact-version="v11"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train {
  width: 108.67% !important;
  max-width: none !important;
  margin-left: 0 !important;
}
/* V12/V13 vergroessern das Motiv mobil, behalten den rechten Endpunkt aber bei
   rund 65 Prozent. Die zusaetzliche Breite wird ausschliesslich links gekappt. */
tr[data-rt-artifact-version="v12"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train,
tr[data-rt-artifact-version="v13"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train {
  width: 135% !important;
  max-width: none !important;
  margin-left: -15.75% !important;
}
/* V14 vergroessert das Telefonmotiv deutlich. Da das Ankunftsbild bei 60 %
   seiner Leinwand endet, ergeben 175 % Breite minus 40 % linken Beschnitt
   weiterhin exakt 65 % der Signaturbreite. */
tr[data-rt-artifact-version="v14"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train,
tr[data-rt-artifact-version="v15"] .rt-sign-train-layer[data-rt-layer-mobile="stop65"] .rt-sign-train {
  width: 175% !important;
  max-width: none !important;
  margin-left: -40% !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-train-layer[data-rt-layer-mobile="stop60"] .rt-sign-train {
  width: 160% !important;
  max-width: none !important;
  margin-left: -36% !important;
}
/* V17/V18/V20 bleiben proportional, sind mobil etwas groesser und stoppen mit
   Reserve vor der rechten Kante. Die zusaetzliche Breite wird links
   angeschnitten. */
tr[data-rt-artifact-version="v17"] .rt-sign-train-layer[data-rt-layer-mobile="stop60"] .rt-sign-train,
tr[data-rt-artifact-version="v18"] .rt-sign-train-layer[data-rt-layer-mobile="stop60"] .rt-sign-train,
tr[data-rt-artifact-version="v19"] .rt-sign-train-layer[data-rt-layer-mobile="stop60"] .rt-sign-train,
tr[data-rt-artifact-version="v20"] .rt-sign-train-layer[data-rt-layer-mobile="stop60"] .rt-sign-train {
  width: 164% !important;
  max-width: none !important;
  height: auto !important;
  margin-left: -40% !important;
}


tr.rt-stack > td + td { padding-top: 10px !important; }
.rt-sign-company { padding-top: 10px !important; }
.rt-pad { padding-top: 14px !important; padding-bottom: 14px !important; }
/* Die allgemeine Telefonverdichtung darf am unteren Zug-Carrier keinen
   neuen Leerraum vor dem Pflichtangaben-Footer einfuehren. */
.rt-sign-content { padding-top: 0 !important; padding-bottom: 15px !important; }
/* V9 behaelt unter der roten Oberkante einen kleinen, mail-sicheren
   Innenabstand. Gleichzeitig werden die Abstaende zwischen Wortmarke,
   Person und Firmendaten etwas verdichtet, damit die feste Mobilbuehne
   und die bei 65 Prozent endende Zugposition unveraendert bleiben. */
tr[data-rt-artifact-version="v9"] .rt-sign-content {
  padding-top: 10px !important;
}
tr[data-rt-artifact-version="v9"] .rt-sign-logo {
  padding-bottom: 10px !important;
}
tr[data-rt-artifact-version="v9"] .rt-sign-top-row > .rt-sign-identity {
  padding-top: 8px !important;
}
tr[data-rt-artifact-version="v9"] .rt-sign-company {
  padding-top: 8px !important;
}
tr[data-rt-artifact-version="v9"] .rt-sign-identity .rt-contact {
  margin-top: 8px !important;
}
tr[data-rt-artifact-version="v9"] .rt-company-contact {
  margin-top: 10px !important;
}
/* V10 nutzt die durch 270 px begrenzte Mobilbuehne noch kompakter. Die
   Tabellenzellen behalten positive Pixelabstaende und damit den Mailvertrag. */
tr[data-rt-artifact-version="v10"] .rt-sign-content {
  padding-top: 8px !important;
}
tr[data-rt-artifact-version="v10"] .rt-sign-logo {
  padding-bottom: 8px !important;
}
tr[data-rt-artifact-version="v10"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v10"] .rt-sign-company {
  padding-top: 6px !important;
}
tr[data-rt-artifact-version="v10"] .rt-sign-identity .rt-contact {
  margin-top: 6px !important;
}
tr[data-rt-artifact-version="v10"] .rt-company-contact {
  margin-top: 8px !important;
}
/* V11 bis V14 geben der Wortmarke unter der roten Linie etwas mehr Luft. Die uebrigen
   kompakten Abstaende bleiben bewusst identisch zu V10. */
tr[data-rt-artifact-version="v11"] .rt-sign-content,
tr[data-rt-artifact-version="v12"] .rt-sign-content,
tr[data-rt-artifact-version="v13"] .rt-sign-content,
tr[data-rt-artifact-version="v14"] .rt-sign-content,
tr[data-rt-artifact-version="v15"] .rt-sign-content {
  padding-top: 14px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-logo,
tr[data-rt-artifact-version="v12"] .rt-sign-logo,
tr[data-rt-artifact-version="v13"] .rt-sign-logo,
tr[data-rt-artifact-version="v14"] .rt-sign-logo,
tr[data-rt-artifact-version="v15"] .rt-sign-logo {
  padding-bottom: 8px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v11"] .rt-sign-company,
tr[data-rt-artifact-version="v12"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v12"] .rt-sign-company,
tr[data-rt-artifact-version="v13"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v13"] .rt-sign-company,
tr[data-rt-artifact-version="v14"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v14"] .rt-sign-company,
tr[data-rt-artifact-version="v15"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v15"] .rt-sign-company {
  padding-top: 6px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-content,
tr[data-rt-artifact-version="v17"] .rt-sign-content,
tr[data-rt-artifact-version="v18"] .rt-sign-content,
tr[data-rt-artifact-version="v19"] .rt-sign-content,
tr[data-rt-artifact-version="v20"] .rt-sign-content,
tr[data-rt-artifact-version="v21"] .rt-sign-content {
  padding-top: 14px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-logo,
tr[data-rt-artifact-version="v17"] .rt-sign-logo,
tr[data-rt-artifact-version="v18"] .rt-sign-logo,
tr[data-rt-artifact-version="v19"] .rt-sign-logo,
tr[data-rt-artifact-version="v20"] .rt-sign-logo {
  padding-bottom: 8px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v16"] .rt-sign-company {
  padding-top: 6px !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v17"] .rt-sign-company,
tr[data-rt-artifact-version="v18"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v18"] .rt-sign-company,
tr[data-rt-artifact-version="v19"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v19"] .rt-sign-company,
tr[data-rt-artifact-version="v20"] .rt-sign-top-row > .rt-sign-identity,
tr[data-rt-artifact-version="v20"] .rt-sign-company {
  padding-top: 6px !important;
}
tr[data-rt-artifact-version="v11"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v12"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v13"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v14"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v15"] .rt-sign-identity .rt-contact {
  margin-top: 6px !important;
}
tr[data-rt-artifact-version="v11"] .rt-company-contact,
tr[data-rt-artifact-version="v12"] .rt-company-contact,
tr[data-rt-artifact-version="v13"] .rt-company-contact,
tr[data-rt-artifact-version="v14"] .rt-company-contact,
tr[data-rt-artifact-version="v15"] .rt-company-contact {
  margin-top: 8px !important;
}
tr[data-rt-artifact-version="v16"] .rt-sign-identity .rt-contact {
  margin-top: 6px !important;
}
tr[data-rt-artifact-version="v17"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v18"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v19"] .rt-sign-identity .rt-contact,
tr[data-rt-artifact-version="v20"] .rt-sign-identity .rt-contact {
  margin-top: 6px !important;
}
tr[data-rt-artifact-version="v16"] .rt-company-contact {
  margin-top: 8px !important;
}
tr[data-rt-artifact-version="v17"] .rt-company-contact,
tr[data-rt-artifact-version="v18"] .rt-company-contact,
tr[data-rt-artifact-version="v19"] .rt-company-contact,
tr[data-rt-artifact-version="v20"] .rt-company-contact {
  margin-top: 8px !important;
}
}

/* V21: setzt nur die historischen Buehnenwerte zurueck. Alle sichtbaren
   Basiswerte stehen bereits inline im normalen Tabellenfluss. */
tr[data-rt-artifact-version="v21"] .rt-sign-stage,
tr[data-rt-artifact-version="v21"] .rt-sign-content-frame,
tr[data-rt-artifact-version="v21"] .rt-sign-train-layer {
  position: static !important;
  height: auto !important;
  max-height: none !important;
}
tr[data-rt-artifact-version="v21"] .rt-sign-stage { overflow: visible !important; }
tr[data-rt-artifact-version="v21"] .rt-sign-train-layer {
  max-width: 720px !important;
  margin: 0 auto 0 0 !important;
  /* Laravels CSS-Inliner verrechnet Shorthand und Longhand nicht.
     Ohne diese Einzelwerte gelangt die alte negative margin-bottom
     trotz des Resets als Inline-!important in die versandte Nachricht. */
  margin-top: 0 !important;
  margin-right: auto !important;
  margin-bottom: 0 !important;
  margin-left: 0 !important;
}
tr[data-rt-artifact-version="v21"] .rt-sign-train-frame,
tr[data-rt-artifact-version="v21"] .rt-sign-train-slot {
  height: auto !important;
}
tr[data-rt-artifact-version="v21"] .rt-sign-train-layer[data-rt-layer-train] .rt-sign-train,
tr[data-rt-artifact-version="v21"] .rt-sign-train-layer[data-rt-layer-train] .rt-sign-train-mso {
  display: block !important;
  width: 100% !important;
  max-width: 720px !important;
  height: auto !important;
  margin: 0 !important;
}

/* V22: optionale Dekoration auf derselben Zelle wie der Inhalt. Keine
   zweite Bildzeile, feste Buehnenhoehe oder negative Ueberlappung. Die
   Grundanordnung bleibt auch ohne Bilder und ohne Head-CSS lesbar. */
tr[data-rt-artifact-version="v22"] .rt-sign-content-frame {
  position: static !important;
  height: auto !important;
  min-height: 0 !important;
  max-height: none !important;
}
tr[data-rt-artifact-version="v22"] .rt-sign-cell {
  background-position: 65% bottom !important;
  background-repeat: no-repeat !important;
}
tr[data-rt-artifact-version="v22"] .rt-sign-logo {
  text-align: right !important;
}
tr[data-rt-artifact-version="v22"] img.rt-logo {
  margin-left: auto !important;
  margin-right: 0 !important;
}
@media only screen and (max-width: 860px) {
  /* Die Werte stammen aus derselben begrenzten Liste wie der Serververtrag.
     Hoehe auto wahrt die Proportionen; der 65%-Anker haelt die Lok im Bild. */
  @foreach (\App\Support\Mail\SignatureBackgroundContract::SIZES as $backgroundSize)
  tr[data-rt-artifact-version="v22"] .rt-sign-cell[data-rt-signature-background="1"][data-rt-bg-tablet="{{ $backgroundSize }}"] {
    background-size: {{ $backgroundSize }}% auto !important;
  }
  @endforeach
  tr[data-rt-artifact-version="v22"] .rt-sign-content {
    padding: 18px 24px 15px !important;
  }
}
@media only screen and (max-width: 480px) {
  @foreach (\App\Support\Mail\SignatureBackgroundContract::SIZES as $backgroundSize)
  tr[data-rt-artifact-version="v22"] .rt-sign-cell[data-rt-signature-background="1"][data-rt-bg-mobile="{{ $backgroundSize }}"] {
    background-size: {{ $backgroundSize }}% auto !important;
  }
  @endforeach
  tr[data-rt-artifact-version="v22"] .rt-sign-content {
    padding: 18px 20px 15px !important;
  }
  tr[data-rt-artifact-version="v22"] .rt-sign-logo {
    padding: 0 0 12px !important;
    text-align: left !important;
  }
  tr[data-rt-artifact-version="v22"] img.rt-logo {
    margin-left: 0 !important;
    margin-right: auto !important;
  }
}
