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

    GESTAPELT BLEIBT DIE QUELLREIHENFOLGE ERHALTEN: erst die Person, dann
    dieselbe Firmenspalte. Wortmarke und Firmendaten werden nicht fuer Mobil
    gedoppelt oder per CSS umsortiert. Dadurch bleibt auch ein Reply- oder
    Forward-Zitat korrekt, wenn ein Mailclient Media-Queries entfernt.

    @param string $border  Farbwert der Trennlinie (SIGNATURE_BORDER)
--}}
@php
    $border = $border ?? '#e6e8ec';
@endphp
/* RT_SERVER_SIGNATURE_RUNTIME_START
   Stabiler Einhaengepunkt fuer freigegebenes Signatur-CSS. Zug-Einfahrt,
   Idle-Rauch und Schlusszustand stecken vollstaendig im einen Haupt-GIF;
   ein zweites Rauch-Overlay wird nicht mehr erzeugt. */

/* Der Zug bleibt ein regulaeres IMG. Nur sein absoluter Layer wird auf
   kleinen Flaechen breiter als der Carrier gesetzt; so endet die Lok wie im
   Desktoplayout bei etwa 75 Prozent, ohne eine eigene Tabellenzeile. */
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
/* SIGNATUR OHNE RESPONSIVE DOM-DOPPELUNGEN. Person und Firma werden in
   ihrer Quellreihenfolge gestapelt. Dieselbe Wortmarke und dieselben
   Firmendaten bleiben sichtbar; es gibt keine versteckte Mobilfassung, die
   ein Reply-/Forward-Client versehentlich zusaetzlich einblenden koennte. */
.rt-sign-identity { padding: 0 !important; }
.rt-sign-logo {
  padding: 14px 0 0 !important;
  border-left: 0 !important;
  border-top: 1px solid {{ $border }} !important;
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

/* Raster, Wasserzeichen und Grundschleier bleiben mobil an ihre drei
   kanonischen Ebenen gekoppelt. Der Zug ist ein normales IMG. */
.rt-sign-cell {
  background-position: left top, right center, center center !important;
  background-size: 64px 64px, auto 52%, 100% 100% !important;
}
.rt-sign-train-layer {
  left: 0 !important;
  right: auto !important;
  width: 100% !important;
  max-width: none !important;
  margin: 0 !important;
}
/* Das Asset selbst endet bei rund 75 Prozent. Fuer den Standardausschnitt ist
   deshalb keine 200-Prozent-Vergroesserung noetig; sie legte den kompletten
   Zug ueber die unteren Kontaktzeilen. Nur die expliziten Detailausschnitte
   vergroessern weiterhin. */
.rt-sign-train-layer[data-rt-layer-mobile="left"] { left: 0 !important; width: 200% !important; }
.rt-sign-train-layer[data-rt-layer-mobile="center"] { left: -50% !important; width: 200% !important; }
.rt-sign-train-layer[data-rt-layer-mobile="train"] { left: 0 !important; width: 100% !important; }
.rt-sign-train-layer[data-rt-layer-mobile="right"] { left: -100% !important; width: 200% !important; }
.rt-sign-train {
  left: 0 !important;
  right: auto !important;
  width: 100% !important;
  max-width: none !important;
}
}

/* ---- Telefon: Innenabstaende weiter zuruecknehmen ---- */
@media only screen and (max-width: 480px) {
.rt-pad { padding-left: 18px !important; padding-right: 18px !important; }
.rt-title { font-size: 24px !important; line-height: 29px !important; }
.rt-sign-name { font-size: 17px !important; line-height: 21px !important; }
img.rt-logo { width: 138px !important; }


tr.rt-stack > td + td { padding-top: 10px !important; }
.rt-pad { padding-top: 14px !important; padding-bottom: 14px !important; }
/* Die allgemeine Telefonverdichtung darf am unteren Zug-Carrier keinen
   neuen Leerraum vor dem Pflichtangaben-Footer einfuehren. */
.rt-sign-content { padding-bottom: 0 !important; }
}
