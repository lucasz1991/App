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
      <=  860 px  Tablet hoch  — stapeln: Person oben, Firma darunter, Karten
                                 der Vorlage einspaltig.
      <=  480 px  Telefon      — Innenabstaende weiter zuruecknehmen.

    GESTAPELT BLEIBT DIE ACHSE ERHALTEN: die Personenspalte schliesst links
    ab, die Firmenspalte rechts. Beide Bloecke sind dadurch je fuer sich
    buendig — die Symbole der Firmenspalte stehen in der Quelle hinter dem
    Text und liessen sich per CSS ohnehin nicht zuverlaessig umstellen.

    @param string $border  Farbwert der Trennlinie (SIGNATURE_BORDER)
--}}
@php
    $border = $border ?? '#e6e8ec';
@endphp
/* RT_SERVER_IDLE_REVEAL_START
   Dieser Block wird erst serverseitig ueber den RESPONSIVE_CSS-Platzhalter eingesetzt,
   nachdem das editierbare Maildokument sanitisiert wurde. Das Overlay ist
   inline unsichtbar und layoutneutral; nur Clients mit CSS-Keyframes zeigen
   die reine Rauchschleife nach dem 13-s-Haupt-GIF. */
@keyframes rt-train-idle-reveal {
  0% { opacity: 0; visibility: hidden; }
  100% { opacity: 1; visibility: visible; }
}
.rt-train-idle-overlay {
  opacity: 0;
  visibility: hidden;
}
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
.rt-train-idle-surface {
  position: absolute !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100% !important;
  height: 100% !important;
  max-width: none !important;
  max-height: none !important;
  z-index: 0 !important;
}
@media (prefers-reduced-motion: reduce) {
  .rt-train-idle-overlay {
    animation: none !important;
    opacity: 0 !important;
    visibility: hidden !important;
  }
}
/* RT_SERVER_IDLE_REVEAL_END */

/* ---- Sehr breite Mailflaechen: Zug am linken Rand verankern ---------
   Bei 184 px Carrierhoehe ist das 2880-x-292-Asset 1814,8 CSS-Pixel
   breit. Oberhalb davon wuerde die sonst richtige 75-Prozent-Position
   das vollstaendige Zugheck immer weiter nach rechts schieben. Ab 1820 px
   beginnt deshalb das Haupt-GIF exakt links; die Front darf mit wachsender
   Flaeche bewusst unter 75 Prozent fallen. Das spaeter eingeblendete
   Idle-GIF muss pixelgleich an derselben Stelle uebernehmen. Clients ohne
   min-width-Media-Queries behalten fail-closed die normale 75-Prozent-
   Position aus dem Inline-Stil. */
@media only screen and (min-width: 1820px) {
.rt-sign-cell {
  background-position: left top, right center, center center, left bottom !important;
}
.rt-train-idle-surface {
  background-position: left bottom !important;
}
}

/* ---- Tablet quer: enger setzen, aber zweispaltig bleiben ---- */
@media only screen and (max-width: 1000px) {
.rt-pad { padding-left: 30px !important; padding-right: 30px !important; }
.rt-title { font-size: 30px !important; line-height: 35px !important; }
.rt-sign-logo { padding-left: 18px !important; }
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
.rt-pad { padding-left: 24px !important; padding-right: 24px !important; }
.rt-title { font-size: 27px !important; line-height: 32px !important; }
{{-- Nur DIREKTE Zellen der markierten Zeile umbrechen: ein Nachfahren-
     Selektor wuerde auch die verschachtelte Kontakttabelle zerlegen und
     Symbol und Text untereinander werfen. --}}
tr.rt-stack > td { box-sizing: border-box !important; display: block !important; width: 100% !important; }
tr.rt-stack > td + td { padding-top: 12px !important; }
/* Gestapelte Kartenzellen brauchen keine Trennlinie zur Seite mehr. */
.rt-card-cell { border-right: 0 !important; }
/* Schutzregel: Symbol und Kontaktzeile bleiben nebeneinander, auch wenn
   ein Client den Kindselektor oben zu einem Nachfahren umbaut. */
.rt-contact { width: auto !important; }
.rt-contact td.rt-contact-icon, .rt-contact td.rt-contact-text { display: table-cell !important; box-sizing: content-box !important; padding-top: 0 !important; }
.rt-contact td.rt-contact-icon { width: 22px !important; }
.rt-contact td.rt-contact-text { width: auto !important; font-size: 13px !important; line-height: 19px !important; }
.rt-sign-name { font-size: 21px !important; line-height: 25px !important; }
/* MOBIL: WORTMARKE OBEN, DARUNTER PERSON LINKS UND FIRMA RECHTS.
   Die Wortmarke steht dafuer zweimal im Markup — im Breitlayout in der
   rechten Spalte, gestapelt als eigene Zeile ganz oben. Hier wird
   getauscht, welche der beiden sichtbar ist. */
.rt-marke-mobil { display: block !important; max-height: none !important; overflow: visible !important; }
.rt-sign-logo img.rt-logo { display: none !important; }

/* DIE BEIDEN SPALTEN BLEIBEN NEBENEINANDER. Die Regel eine Zeile weiter
   oben bricht ALLE markierten Zeilen um — die Karten der Vorlage sollen
   das auch. Die Signatur nicht: dort stuenden Person und Firma sonst
   untereinander, und die Listen begaennen auf verschiedenen Hoehen.
   Deshalb hier ausdruecklich zurueckgenommen. */
tr.rt-stack > td.rt-sign-identity,
tr.rt-stack > td.rt-sign-logo {
  display: table-cell !important;
  width: 50% !important;
  padding-top: 0 !important;
}
.rt-sign-identity { padding: 0 12px 0 0 !important; }
.rt-person-kopf, .rt-sign-identity .rt-contact,
.rt-firma-links, .rt-firma-rechts {
  display: block !important;
  width: auto !important;
  margin-left: 0 !important;
  margin-right: 0 !important;
}
/* Beide Listen beginnen auf DERSELBEN Hoehe. Links kosten Name und
   Funktion Platz, den die Firmenspalte als Vorlauf nachbildet — sonst
   staende sie hoeher und die Zeilen liefen gegeneinander. */
.rt-sign-identity .rt-contact { margin-top: 10px !important; }
.rt-firma-links { margin-top: 52px !important; }
.rt-firma-rechts { margin-top: 4px !important; }
/* Gestapelt traegt die ungespiegelte Fassung: Symbol links, Text rechts
   daneben, der ganze Block linksbuendig — genau wie der Personenblock
   darueber. */
.rt-firma-breit { display: none !important; max-height: 0 !important; overflow: hidden !important; }
.rt-firma-schmal { display: block !important; max-height: none !important; overflow: visible !important; }
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
.rt-marke-mobil img { width: 150px !important; margin-bottom: 12px !important; }
.rt-sign-logo { padding: 0 0 0 12px !important; }
/* Die Firmenliste sitzt gestapelt direkt unter der Wortmarke — der
   Vorsprung, den sie im Breitlayout ausgleicht, faellt hier weg. */

/* Gestapelt stehen die Spalten untereinander — die rechten 30 % sind dann
   nicht mehr fuer die Firmendaten reserviert und der Zug darf ueber die
   ganze Breite laufen. Die Reihenfolge der Werte folgt der Ebenenliste in
   signature.blade.php: Raster, Wasserzeichen, Schleier, Zug. Wer sie dort
   aendert, muss sie HIER mitziehen — sonst treffen die Groessen die
   falschen Ebenen. */
/* AUF SCHMALEN SCHIRMEN AN DER BREITE AUSRICHTEN, NICHT AN DER HOEHE.
   Im Breitlayout haengt der Zug an der Streifenhoehe (auto 100%). Das
   passt dort, weil der Streifen flach ist. Auf dem Telefon ist er fast
   quadratisch — dieselbe Regel vergroesserte den Zug so stark, dass ein
   einziger Wagen die Breite fuellte und das Motiv nicht mehr als Zug
   lesbar war. Ueber die Breite skaliert bleiben Lok und drei Wagen im
   Bild.
   Aus demselben Grund wird der Markenschein begrenzt: an der Hoehe
   ausgerichtet legte er sich als breiter Schleier ueber die halbe Karte.
   Er soll ein Schein in der Ecke sein, kein Farbfeld. */
.rt-sign-cell {
  background-position: left top, right center, center center, 75% 84% !important;
  background-size: 64px 64px, auto 52%, 100% 100%, 200% auto !important;
}
.rt-train-idle-surface {
  background-position: 75% 84% !important;
  background-size: 200% auto !important;
}
}

/* ---- Telefon: Innenabstaende weiter zuruecknehmen ---- */
@media only screen and (max-width: 480px) {
.rt-pad { padding-left: 18px !important; padding-right: 18px !important; }
.rt-title { font-size: 24px !important; line-height: 29px !important; }
.rt-sign-name { font-size: 17px !important; line-height: 21px !important; }
.rt-marke-mobil img { width: 138px !important; margin-bottom: 10px !important; }


tr.rt-stack > td + td { padding-top: 10px !important; }
.rt-pad { padding-top: 14px !important; padding-bottom: 14px !important; }
}
