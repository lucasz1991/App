{{--
    EINZIGE Quelle des RailTime-Signaturblocks.

    Genutzt von:
      - den herunterladbaren Signaturen (signature-*-master.html)
      - der herunterladbaren E-Mail-Vorlage (email-master.html)
      - jeder Laravel-Mail und -Notification (vendor/mail/html/footer.blade.php)

    Gerendert werden ZWEI Tabellenzeilen: der Signaturblock (mit dem
    Dampflok-Gueterzug als Hintergrund) und die Pflichtangaben darunter.
    Der Aufrufer stellt die umgebende <table> — dadurch passt derselbe Block
    in die schmale Signaturdatei wie in die breite Nachrichtenschale.

    AUFBAU: zwei gleich breite Spalten an einer Mittelachse. Links die
    Person (Symbole am linken Rand), rechts die Firma (Wortmarke und
    Symbole am rechten Rand). Beide Kontaktlisten beginnen auf derselben
    Hoehe — der Vorsprung der Personenspalte (Name plus Funktion sind
    hoeher als die Wortmarke) steckt im groesseren Abstand der rechten
    Liste, siehe company-contact-table.blade.php.

    Die Markenspalte zeigt bewusst NUR den Schriftzug: das RT-Zeichen davor
    doppelte die Marke auf engem Raum, und der Claim darunter kostete eine
    Zeile, ohne etwas zu sagen. Das Zeichen steht jetzt allein oben rechts
    in der E-Mail-Vorlage.

    Alle Werte kommen bereits HTML-escaped aus App\Support\MailSignature.
    Optionale Zeilen (Durchwahl, Mobil, Website, Firmentelefon) tragen die
    RT_*_START/END-Marker, damit der EmailTemplateBuilder sie fuer leere
    Werte vollstaendig entfernen kann.

    @param array  $values   Werte- und Farbtabelle (siehe MailSignature)
    @param string $padding  Innenabstand der Signaturzelle
    @param string $topRule  Akzentlinie oben (in der Signaturdatei leer)
--}}
@php
    $padding = $padding ?? '18px 36px 20px';
    $topRule = $topRule ?? 'border-top:5px solid #e4002b;';
    $legalPadding = $legalPadding ?? '14px 36px';
    $outlookTrainSrc = trim((string) ($outlookTrainSrc ?? ''));
    // Standbild fuer Outlook-Desktop. Leer = kein bedingter Kommentar.
    $outlookTrainFallbackSrc = trim((string) ($outlookTrainFallbackSrc ?? ''));
    $isOutlookExport = $outlookTrainSrc !== '';
    $cellPadding = $isOutlookExport ? '0' : $padding;
    $outlookTrainPadding = $outlookTrainPadding ?? '6px 0 14px';
    $hasPerson = trim((string) ($values['VORNAME_NACHNAME'] ?? '')) !== '';
    $hasIdleTrain = ! $isOutlookExport && $values['TRAIN_IDLE_SRC'] !== '';
    $trainBackgroundPosition = $hasIdleTrain
        ? 'center center,left bottom,left bottom'
        : 'center center,left bottom';
    // Der Streifen ist flacher als vorher; bliebe der Zug bei 86 % Breite,
    // fuellte er ihn fast ganz aus und waere kein Hintergrund mehr.
    $trainBackgroundSize = $hasIdleTrain
        ? '100% 100%,72% auto,72% auto'
        : '100% 100%,72% auto';
@endphp
<tr>
    {{-- Reihenfolge beachten: die background-Kurzform setzt background-image
         zurueck und muss deshalb VOR der Bildangabe stehen. Clients ohne
         CSS-Hintergrundbilder (Outlook-Desktop, Gmail bei data-URIs) zeigen
         schlicht die Farbflaeche — es geht kein Inhalt verloren. --}}
    <td class="rt-pad rt-sign-cell" bgcolor="{{ $values['SIGNATURE_BG'] }}" style="padding:{{ $cellPadding }};background:{{ $values['SIGNATURE_BG'] }};@unless($isOutlookExport)background-image:linear-gradient({{ $values['SIGNATURE_TRAIN_WASH'] }},{{ $values['SIGNATURE_TRAIN_WASH'] }}),url('{{ $values['TRAIN_SRC'] }}')@if($hasIdleTrain),url('{{ $values['TRAIN_IDLE_SRC'] }}')@endif;background-repeat:no-repeat;background-position:{{ $trainBackgroundPosition }};background-size:{{ $trainBackgroundSize }};@endunless{{ $topRule }}">
        @if($isOutlookExport)
            {{-- Der Inhalt behaelt seinen gewohnten Innenabstand, waehrend
                 die nachfolgende Zugzeile bis an die Signaturkante reicht. --}}
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:{{ $padding }};">
        @endif
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
            <tr class="rt-stack">
                <td class="rt-sign-identity" width="50%" valign="top" align="left" style="width:50%;padding:0 24px 0 0;position:relative;z-index:1;text-align:left;vertical-align:top;">
                    <p class="rt-sign-name" style="margin:0 0 4px;color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};font-size:23px;line-height:27px;font-weight:bold;letter-spacing:-.5px;">{{ $hasPerson ? $values['VORNAME_NACHNAME'] : $values['FIRMENNAME'] }}</p>
                    <p style="margin:0;color:{{ $values['SIGNATURE_ACCENT'] }};font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">{{ $values['POSITION'] }}</p>

                    <table class="rt-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:0;margin-right:auto;margin-top:14px;border-collapse:collapse;">
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
                    </table>
                </td>
                {{-- Die Trennlinie sitzt an der Firmenspalte. Beim Stapeln
                     wandert sie nach oben (siehe responsive-css). --}}
                <td class="rt-sign-logo" width="50%" valign="top" align="right" style="width:50%;padding-left:24px;border-left:1px solid {{ $values['SIGNATURE_BORDER'] }};text-align:right;vertical-align:top;">
                    <img class="rt-logo" src="{{ $values['LOGO_SRC'] }}" width="210" alt="{{ $values['FIRMENNAME'] }}" style="display:block;width:210px;max-width:100%;height:auto;margin-left:auto;">
                    {{-- Im unpersoenlichen Fall stehen Firmentelefon und
                         Firmen-E-Mail bereits links an der Stelle von
                         Durchwahl und Mailadresse. Rechts blieben sie eine
                         sichtbare Doppelung. --}}
                    @include('emails.parts.company-contact-table', [
                        'values' => $values,
                        'align' => 'right',
                        'ohneDoppelung' => ! $hasPerson,
                    ])
                </td>
            </tr>
        </table>
        @if($isOutlookExport)
                    </td>
                </tr>
            </table>
            {{-- Der Zug als REGULAERES Bild statt als Hintergrundebene.
                 Zwei Wege nutzen das:

                 - Outlook-Export: lokale Datei, damit Outlook das GIF als
                   echte Signaturressource uebernimmt.
                 - Versendete Systemmail: verlinkte Adresse, weil weder
                   Outlook-Desktop noch Gmail data:-URIs oder CSS-Hinter-
                   grundbilder darstellen. Als Hintergrund erschien der Zug
                   dort schlicht nie.

                 GETRENNTE QUELLEN JE CLIENT: Outlook zeigt von einem
                 animierten GIF nur das ERSTE Einzelbild — und das ist bei
                 beiden Zugfassungen komplett leer (nachgemessen: 0 % Tinte),
                 weil der Zug dort noch ausserhalb des Bildes steht. Outlook
                 bekommt deshalb ueber den bedingten Kommentar das Standbild
                 in Ruhestellung, alle anderen das GIF. --}}
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td align="left" style="padding:{{ $outlookTrainPadding }};text-align:left;font-size:0;line-height:0;">
                        @if ($outlookTrainFallbackSrc !== '')
                            <!--[if !mso]><!-->
                            <img data-rt-outlook-train src="{{ $outlookTrainSrc }}" width="560" alt="Dampflok-Güterzug" style="display:block;width:560px;max-width:100%;height:auto;margin:0;border:0;outline:none;">
                            <!--<![endif]-->
                            <!--[if mso]>
                            <img data-rt-outlook-train-still src="{{ $outlookTrainFallbackSrc }}" width="560" alt="Dampflok-Güterzug" style="display:block;width:560px;height:auto;margin:0;border:0;outline:none;">
                            <![endif]-->
                        @else
                            <img data-rt-outlook-train src="{{ $outlookTrainSrc }}" width="560" alt="Dampflok-Güterzug" style="display:block;width:560px;max-width:100%;height:auto;margin:0;border:0;outline:none;">
                        @endif
                    </td>
                </tr>
            </table>
        @endif
    </td>
</tr>
<tr>
    <td class="rt-pad" bgcolor="{{ $values['SIGNATURE_LEGAL_BG'] }}" style="padding:{{ $legalPadding }};background:{{ $values['SIGNATURE_LEGAL_BG'] }};color:{{ $values['SIGNATURE_LEGAL_TEXT'] }};font-size:9px;line-height:15px;">
        Geschäftsführung: {{ $values['GESCHAEFTSFUEHRUNG'] }} · Registergericht: {{ $values['REGISTERGERICHT'] }} · HRB {{ $values['HRB'] }} · USt-IdNr. {{ $values['UST_ID'] }} · Steuernummer {{ $values['STEUERNUMMER'] }}<br>
        Diese E-Mail kann vertrauliche Informationen enthalten. Sollten Sie nicht der vorgesehene Empfänger sein, informieren Sie bitte den Absender und löschen Sie diese Nachricht.
    </td>
</tr>
