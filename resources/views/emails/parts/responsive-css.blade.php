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
.rt-pad { padding-left: 18px !important; padding-right: 18px !important; }
.rt-title { font-size: 24px !important; line-height: 29px !important; }
.rt-sign-name { font-size: 17px !important; line-height: 21px !important; }
img.rt-logo { width: 138px !important; }

/* Auf Telefonbreite ist der Zug 75 Prozent groesser als der Viewport. Sein
   sichtbares Motiv endet dadurch knapp ausserhalb der rechten Kante: Die Lok
   bleibt praesent, der lange Zug faehrt bewusst nicht vollstaendig ein. */
.rt-sign-train-layer[data-rt-layer-mobile="train"][data-rt-layer-size] .rt-sign-train,
.rt-sign-train-layer[data-rt-layer-mobile="train"][data-rt-layer-size] .rt-train-idle-image {
  width: 175% !important;
  max-width: none !important;
  margin-left: 0 !important;
}


tr.rt-stack > td + td { padding-top: 10px !important; }
.rt-sign-company { padding-top: 10px !important; }
.rt-pad { padding-top: 14px !important; padding-bottom: 14px !important; }
/* Die allgemeine Telefonverdichtung darf am unteren Zug-Carrier keinen
   neuen Leerraum vor dem Pflichtangaben-Footer einfuehren. */
.rt-sign-content { padding-top: 0 !important; padding-bottom: 15px !important; }
}
