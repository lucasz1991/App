{{--
    EINZIGE Quelle des RailTime-Signaturblocks.

    Genutzt von:
      - den herunterladbaren Signaturen (signature-*-master.html)
      - der herunterladbaren E-Mail-Vorlage (email-master.html)
      - jeder Laravel-Mail und -Notification (vendor/mail/html/footer.blade.php)

    Der kanonische Editorstand besitzt zwei Tabellenzeilen: Signaturblock und
    Pflichtangaben. Im Schema-25-Editorstand ist der Zug wie Logo und Icons ein
    regulaeres IMG. Sein 200-Pixel-Flow-Layer steht im DOM vor dem ebenfalls
    200 Pixel hohen Inhaltswrapper. Die feste -200px-Margin legt beide Flaechen
    exakt uebereinander; eine mail-sichere Tabellenzelle richtet das Zugbild
    direkt an der Oberkante des Pflichtfooters aus. Das Motiv ist auf Desktop
    moderat auf 125 Prozent vergroessert und mittig beschnitten, damit die Lok
    weiterhin ungefaehr bei 60 Prozent endet. Es gibt keine Umrechnung aus
    einer Prozent-Margin und keine vom Editor-Viewport abhaengige Hoehe.

    AUFBAU: Die Wortmarke steht in einer eigenen ersten Tabellenzeile. Darunter
    folgen Person und Firma in ihrer sichtbaren Links-nach-rechts-Reihenfolge.
    Logo und Firmenkontakte existieren jeweils genau einmal. Es gibt weder
    RTL-Reordering noch Rowspan-Abhaengigkeiten; beim Verlust von Head-CSS
    bleibt deshalb eine vollstaendig lesbare Zweispaltenfassung bestehen. Auf
    schmalen Ansichten darf dieselbe zweite Zeile progressiv stapeln.

    Die Markenspalte zeigt bewusst NUR den Schriftzug: das RT-Zeichen davor
    doppelte die Marke auf engem Raum, und der Claim darunter kostete eine
    Zeile, ohne etwas zu sagen. Auch die Wortmarke existiert nur einmal im
    DOM und steht bereits strukturell oberhalb der Person.

    Alle Werte kommen bereits HTML-escaped aus App\Support\MailSignature.
    Optionale Zeilen (Durchwahl, Mobil, Website, Firmentelefon) tragen die
    RT_*_START/END-Marker, damit der EmailTemplateBuilder sie fuer leere
    Werte vollstaendig entfernen kann.

    @param array  $values   Werte- und Farbtabelle (siehe MailSignature)
    @param string $padding  Innenabstand der Signaturzelle
    @param string $topRule  Akzentlinie oben (in der Signaturdatei leer)
--}}
@php
    // Die Daten behalten 15px Luft zur Oberkante des Pflichtfooters. Der Zug
    // bleibt davon unberuehrt am unteren Rand seiner festen 200px-Buehne.
    $padding = $padding ?? '0 36px 15px';
    $topRule = $topRule ?? 'border-top:5px solid #e4002b;';
    $legalPadding = $legalPadding ?? '14px 36px';
    $outlookTrainSrc = trim((string) ($outlookTrainSrc ?? ''));
    $trainSrc = $outlookTrainSrc !== ''
        ? $outlookTrainSrc
        : trim((string) ($values['TRAIN_SRC'] ?? ''));
    $outlookTrainPadding = $outlookTrainPadding ?? '0';
    $hasPerson = trim((string) ($values['VORNAME_NACHNAME'] ?? '')) !== '';
    $pflichtangaben = implode(' · ', array_filter([
        ($values['GESCHAEFTSFUEHRUNG'] ?? '') !== '' ? 'Geschäftsführung: '.$values['GESCHAEFTSFUEHRUNG'] : '',
        ($values['REGISTERGERICHT'] ?? '') !== '' ? 'Registergericht: '.$values['REGISTERGERICHT'] : '',
        ($values['HRB'] ?? '') !== '' ? 'HRB '.$values['HRB'] : '',
        ($values['UST_ID'] ?? '') !== '' ? 'USt-IdNr. '.$values['UST_ID'] : '',
        ($values['STEUERNUMMER'] ?? '') !== '' ? 'Steuernummer '.$values['STEUERNUMMER'] : '',
    ]));
    /* Raster und grosses RT-Wasserzeichen bleiben entfernt. Der bildfreie
     * Wash ist die einzige CSS-Hintergrundebene; der Zug folgt als IMG. */
    $ebenen = [
        "linear-gradient({$values['SIGNATURE_TRAIN_WASH']},{$values['SIGNATURE_TRAIN_WASH']})|center center|100% 100%|no-repeat",
    ];
    $teile = array_map(static fn (string $e): array => explode('|', $e), $ebenen);
    $backgroundImage = implode(',', array_column($teile, 0));
    $backgroundPosition = implode(',', array_column($teile, 1));
    $backgroundSize = implode(',', array_column($teile, 2));
    $backgroundRepeat = implode(',', array_column($teile, 3));
@endphp
<tr>
    <td class="rt-sign-cell" bgcolor="{{ $values['SIGNATURE_BG'] }}" style="padding:0;overflow:hidden;background-color:{{ $values['SIGNATURE_BG'] }};background-image:{{ $backgroundImage }};background-repeat:{{ $backgroundRepeat }};background-position:{{ $backgroundPosition }};background-size:{{ $backgroundSize }};{{ $topRule }}">
        <div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">
        @if($trainSrc !== '')
        <div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="center" data-rt-layer-size="125" data-rt-layer-mobile="train" style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;">
            <table class="rt-sign-train-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">
                <tr>
                    <td class="rt-sign-train-slot" height="200" valign="bottom" style="height:200px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;">
                        <img class="rt-sign-train" data-rt-train src="{{ $trainSrc }}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:125%;max-width:none;height:auto;margin:0 0 0 -12.5%;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;">
                    </td>
                </tr>
            </table>
        </div>
        @endif
        {{-- Der aeussere Carrier bleibt ohne Padding. Der mail-sichere innere
             Tabellenwrapper folgt im DOM auf den Zug-Layer. Seine feste Hoehe
             entspricht dem festen Pixel-Overlap; die spaetere Quellreihenfolge
             haelt die Daten auch ohne z-index vor dem Motiv. --}}
        <table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">
            <tr>
                <td class="rt-pad rt-sign-content" valign="bottom" style="padding:{{ $padding }};position:relative;z-index:1;vertical-align:bottom;">
                    {{-- V18-SAFE-Grundstruktur: Die Quellreihenfolge entspricht
                         der sichtbaren Reihenfolge. Head-CSS darf beim
                         Weiterleiten komplett entfallen, ohne Logo, Person
                         oder Firma umzuschichten oder zu duplizieren. --}}
                    <table class="rt-sign-layout" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;border-collapse:collapse;position:relative;z-index:1;">
                        <tr>
                <td class="rt-sign-logo" colspan="2" width="100%" valign="top" align="right" style="width:100%;padding:0 0 10px;text-align:right;vertical-align:top;">
                    {{-- OUTLOOK BEKOMMT DAS STANDBILD. Die bewegte Marke baut
                         sich Zeichen fuer Zeichen auf; ihr erstes Einzelbild
                         ist deshalb fast leer — und genau dieses eine zeigt
                         Outlook-Desktop. Ohne diese Verzweigung stuende dort
                         eine leere Flaeche statt der Marke.

                         Bewusst mso-hide plus EIN geschlossener bedingter
                         Kommentar: die aufgebrochene, zweiteilige Form laesst
                         der EmailHtmlSanitizer nicht durch. --}}
                    <img class="rt-logo" src="{{ $values['LOGO_SRC'] }}" width="210" alt="{{ $values['FIRMENNAME'] }}" style="display:block;width:210px;max-width:100%;height:auto;margin-left:auto;mso-hide:all;">
                    <!--[if mso]><img class="rt-logo" src="{{ $values['LOGO_STILL_SRC'] ?? $values['LOGO_SRC'] }}" width="210" alt="{{ $values['FIRMENNAME'] }}" style="display:block;width:210px;height:auto;margin-left:auto;"><![endif]-->
                </td>
                        </tr>
                        <tr class="rt-stack rt-sign-top-row">
                {{-- Ohne Person bleibt die Namenszeile LEER: Die Marke steht
                     bereits als Wortmarke darueber, der Firmenname darunter
                     waere eine Doppelung. --}}
                <td class="rt-sign-identity" dir="ltr" width="50%" valign="top" align="left" style="direction:ltr;width:50%;padding:8px 24px 0 0;position:relative;z-index:1;text-align:left;vertical-align:top;">
                    {{-- Eigener Behaelter, damit Name und Funktion gestapelt
                         NEBEN die Kontaktliste ruecken koennen statt darueber
                         (siehe responsive-css: inline-block). --}}
                    <div class="rt-person-kopf">
                    <p class="rt-sign-name" style="margin:0 0 4px;color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};font-size:23px;line-height:27px;font-weight:bold;letter-spacing:-.5px;">{{ $hasPerson ? $values['VORNAME_NACHNAME'] : '' }}</p>
                    <p style="margin:0;color:{{ $values['SIGNATURE_ACCENT'] }};font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">{{ $values['POSITION'] }}</p>
                    </div>

                    <table class="rt-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:0;margin-right:auto;margin-top:14px;border-collapse:collapse;">
                        <tbody>
                        <!-- RT_PHONE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 6px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_PHONE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 6px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="tel:{{ $values['DURCHWAHL_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['DURCHWAHL'] }}</a></td>
                        </tr>
                        <!-- RT_PHONE_END -->
                        <!-- RT_MOBILE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 6px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_MOBILE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 6px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="tel:{{ $values['MOBIL_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['MOBIL'] }}</a></td>
                        </tr>
                        <!-- RT_MOBILE_END -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_EMAIL_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 0 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="mailto:{{ $values['E_MAIL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['E_MAIL'] }}</a></td>
                        </tr>
                        </tbody>
                    </table>
                </td>
                {{-- Die rechte Trennlinie trennt nur die beiden Datenzellen.
                     Mobil verschwindet sie und derselbe einzelne Kontaktblock
                     folgt der Person. --}}
                <td class="rt-sign-company" dir="ltr" width="50%" valign="top" align="right" style="direction:ltr;width:50%;padding:8px 0 0 24px;border-left:1px solid {{ $values['SIGNATURE_BORDER'] }};text-align:right;vertical-align:top;">
                    {{-- Im unpersoenlichen Fall stehen Firmentelefon und
                         Firmen-E-Mail bereits links an der Stelle von
                         Durchwahl und Mailadresse. Rechts blieben sie eine
                         sichtbare Doppelung. --}}
                    {{-- EIN gemeinsamer Kontaktblock fuer alle Breiten.
                         Frueher lagen hier eine rechte Desktop- und eine
                         versteckte linke Mobilkopie. Outlook und andere
                         Clients koennen beim Antworten/Weiterleiten die
                         zugehoerigen Media-Queries entfernen und dadurch
                         beide Fassungen anzeigen. Die Tabelle verwendet
                         deshalb in jeder Ansicht dieselbe mailclient-sichere
                         Reihenfolge: Symbol links, Text rechts. Lediglich die
                         Position des Blocks wird responsiv angepasst. --}}
                    <div align="right" style="text-align:right;">
                        @include('emails.parts.company-contact-table', [
                            'values' => $values,
                            'align' => 'right',
                            'ohneDoppelung' => ! $hasPerson,
                        ])
                    </div>
                </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        </div>
    </td>
</tr>
<!-- RT_SIGNATURE_MAIN_END -->
<tr>
    <td class="rt-pad" bgcolor="{{ $values['SIGNATURE_LEGAL_BG'] }}" style="padding:{{ $legalPadding }};background:{{ $values['SIGNATURE_LEGAL_BG'] }};color:{{ $values['SIGNATURE_LEGAL_TEXT'] }};font-size:9px;line-height:15px;">
        {{-- Beschriftung und Wert gehoeren zusammen: eine nicht gepflegte
             Angabe faellt samt ihrer Beschriftung weg. Vorher endete die
             Zeile in jeder Mail auf ein blankes "Steuernummer", weil die
             Vorgabe dafuer leer ist. --}}
        {{ $pflichtangaben }}<br>
        Diese E-Mail kann vertrauliche Informationen enthalten. Sollten Sie nicht der vorgesehene Empfänger sein, informieren Sie bitte den Absender und löschen Sie diese Nachricht.
    </td>
</tr>
