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
                                 Breitlayout (37 % / 63 % plus 28 px Steg)
                                 und war deshalb gequetscht.
      <=  860 px  Tablet hoch  — stapeln: Spalten untereinander, Karten der
                                 Vorlage einspaltig, Anschrift wechselt von
                                 der Marken- in die Personenspalte.
      <=  480 px  Telefon      — Innenabstaende weiter zuruecknehmen.

    @param string $border  Farbwert der Trennlinie (SIGNATURE_BORDER)
--}}
@php
    $border = $border ?? '#e6e8ec';
@endphp
/* ---- Tablet quer: enger setzen, aber zweispaltig bleiben ---- */
@media only screen and (max-width: 1000px) {
.rt-pad { padding-left: 32px !important; padding-right: 32px !important; }
.rt-title { font-size: 30px !important; line-height: 35px !important; }
.rt-sign-logo { padding-left: 20px !important; }
.rt-sign-logo img { width: 200px !important; }
.rt-sign-identity { padding-right: 20px !important; }
.rt-sign-name { font-size: 23px !important; line-height: 27px !important; }
.rt-only-wide { font-size: 10px !important; line-height: 17px !important; }
.rt-card-cell, tr > td.rt-card { padding-left: 16px !important; padding-right: 16px !important; }
}

/* ---- Tablet hoch und kleiner: stapeln ---- */
@media only screen and (max-width: 860px) {
.rt-pad { padding-left: 24px !important; padding-right: 24px !important; }
.rt-title { font-size: 27px !important; line-height: 32px !important; }
{{-- Nur DIREKTE Zellen der markierten Zeile umbrechen: ein Nachfahren-
     Selektor wuerde auch die verschachtelte Kontakttabelle zerlegen und
     Symbol und Text untereinander werfen. --}}
tr.rt-stack > td { box-sizing: border-box !important; display: block !important; width: 100% !important; }
tr.rt-stack > td + td { padding-top: 30px !important; }
/* Gestapelte Kartenzellen brauchen keine Trennlinie zur Seite mehr. */
.rt-card-cell { border-right: 0 !important; }
/* Schutzregel: Symbol und Kontaktzeile bleiben nebeneinander, auch wenn
   ein Client den Kindselektor oben zu einem Nachfahren umbaut. */
.rt-contact { width: auto !important; }
.rt-contact td.rt-contact-icon, .rt-contact td.rt-contact-text { display: table-cell !important; box-sizing: content-box !important; padding-top: 0 !important; }
.rt-contact td.rt-contact-icon { width: 22px !important; }
.rt-contact td.rt-contact-text { width: auto !important; font-size: 13px !important; line-height: 19px !important; }
.rt-sign-name { font-size: 22px !important; line-height: 26px !important; }
/* Die Markenspalte steht in der Quelle zuerst und landet beim Stapeln
   oben. Die Trennlinie wandert deshalb von links nach unten. */
.rt-sign-logo { border-left: 0 !important; border-bottom: 1px solid {{ $border }} !important; padding: 0 0 12px !important; text-align: left !important; }
.rt-sign-logo img { width: 190px !important; margin-left: 0 !important; }
.rt-sign-identity { padding: 18px 0 0 !important; }
tr.rt-stack > td.rt-sign-identity { padding: 18px 0 0 !important; }
/* Die Anschrift sitzt breit in der Markenspalte (kostet dort keine Hoehe)
   und schmal am Ende des Personenblocks (bessere Lesefolge). */
.rt-only-wide { display: none !important; max-height: 0 !important; overflow: hidden !important; }
.rt-only-narrow { display: block !important; max-height: none !important; overflow: visible !important; font-size: 11px !important; line-height: 18px !important; }
/* Gestapelt ist die Signatur hoeher — dort darf der Zug groesser wirken.
   Der Streifen selbst ist flach auf Desktop ausgelegt. */
.rt-sign-cell { background-size: 100% 100%, 125% auto, 125% auto !important; }
}

/* ---- Telefon: Innenabstaende weiter zuruecknehmen ---- */
@media only screen and (max-width: 480px) {
.rt-pad { padding-left: 18px !important; padding-right: 18px !important; }
.rt-title { font-size: 24px !important; line-height: 29px !important; }
.rt-sign-name { font-size: 20px !important; line-height: 24px !important; }
.rt-sign-logo img { width: 168px !important; }
.rt-sign-identity { padding-top: 14px !important; }
tr.rt-stack > td.rt-sign-identity { padding-top: 14px !important; }
tr.rt-stack > td + td { padding-top: 24px !important; }
}
