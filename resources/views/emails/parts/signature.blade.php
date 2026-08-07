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

    Alle Werte kommen bereits HTML-escaped aus App\Support\MailSignature.
    Optionale Zeilen (Durchwahl, Mobil, Website, Firmentelefon) tragen die
    RT_*_START/END-Marker, damit der EmailTemplateBuilder sie fuer leere
    Werte vollstaendig entfernen kann.

    @param array  $values   Werte- und Farbtabelle (siehe MailSignature)
    @param string $padding  Innenabstand der Signaturzelle
    @param string $topRule  Akzentlinie oben (in der Signaturdatei leer)
--}}
@php
    $padding = $padding ?? '20px 38px 28px';
    $topRule = $topRule ?? 'border-top:5px solid #e4002b;';
    $legalPadding = $legalPadding ?? '18px 38px';
    $hasPerson = trim((string) ($values['VORNAME_NACHNAME'] ?? '')) !== '';
    $hasIdleTrain = $values['TRAIN_IDLE_SRC'] !== '';
    $trainBackgroundPosition = $hasIdleTrain
        ? 'center center,center bottom,center bottom'
        : 'center center,center bottom';
    $trainBackgroundSize = $hasIdleTrain
        ? '100% 100%,100% auto,100% auto'
        : '100% 100%,100% auto';
@endphp
<tr>
    {{-- Reihenfolge beachten: die background-Kurzform setzt background-image
         zurueck und muss deshalb VOR der Bildangabe stehen. Clients ohne
         CSS-Hintergrundbilder (Outlook-Desktop, Gmail bei data-URIs) zeigen
         schlicht die Farbflaeche — es geht kein Inhalt verloren. --}}
    <td class="rt-pad rt-sign-cell" bgcolor="{{ $values['SIGNATURE_BG'] }}" style="padding:{{ $padding }};background:{{ $values['SIGNATURE_BG'] }};background-image:linear-gradient({{ $values['SIGNATURE_TRAIN_WASH'] }},{{ $values['SIGNATURE_TRAIN_WASH'] }}),url('{{ $values['TRAIN_SRC'] }}')@if($hasIdleTrain),url('{{ $values['TRAIN_IDLE_SRC'] }}')@endif;background-repeat:no-repeat;background-position:{{ $trainBackgroundPosition }};background-size:{{ $trainBackgroundSize }};{{ $topRule }}">
        {{-- dir="rtl" dreht ausschliesslich die Spaltenfolge: die Markenspalte
             steht in der Quelle zuerst (und landet beim Stapeln oben), auf
             breiten Schirmen aber weiterhin rechts. --}}
        <table role="presentation" dir="rtl" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
            <tr class="rt-stack">
                <td dir="ltr" class="rt-sign-logo" width="37%" valign="bottom" align="right" style="direction:ltr;width:37%;padding-left:28px;border-left:1px solid {{ $values['SIGNATURE_BORDER'] }};text-align:right;vertical-align:bottom;">
                    <img class="rt-logo" src="{{ $values['LOGO_SRC'] }}" width="240" alt="{{ $values['FIRMENNAME'] }}" style="display:block;width:240px;max-width:100%;height:auto;margin-left:auto;">
                    <p style="margin:14px 0 0;color:{{ $values['SIGNATURE_TEXT_MUTED'] }};font-family:Consolas,'Courier New',monospace;font-size:9px;line-height:15px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Sicher · flexibel · deutschlandweit</p>
                    <div class="rt-only-wide" style="margin:13px 0 0;color:{{ $values['SIGNATURE_META_TEXT'] }};font-size:11px;line-height:18px;"><strong style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};">{{ $values['FIRMENNAME'] }}</strong>@include('emails.parts.company-contact-table', ['values' => $values, 'align' => 'right'])</div>
                </td>
                <td dir="ltr" class="rt-sign-identity" width="63%" valign="top" align="left" style="direction:ltr;width:63%;padding:6px 28px 0 0;position:relative;z-index:1;text-align:left;vertical-align:top;">
                    <p class="rt-sign-name" style="margin:0 0 4px;color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};font-size:25px;line-height:29px;font-weight:bold;letter-spacing:-.5px;">{{ $hasPerson ? $values['VORNAME_NACHNAME'] : $values['FIRMENNAME'] }}</p>
                    <p style="margin:0;color:{{ $values['SIGNATURE_ACCENT'] }};font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">{{ $values['POSITION'] }}</p>

                    <table class="rt-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:0;margin-right:auto;margin-top:17px;border-collapse:collapse;">
                        <!-- RT_PHONE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_PHONE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 8px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="tel:{{ $values['DURCHWAHL_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['DURCHWAHL'] }}</a></td>
                        </tr>
                        <!-- RT_PHONE_END -->
                        <!-- RT_MOBILE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_MOBILE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 8px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="tel:{{ $values['MOBIL_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['MOBIL'] }}</a></td>
                        </tr>
                        <!-- RT_MOBILE_END -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_EMAIL_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 8px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="mailto:{{ $values['E_MAIL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['E_MAIL'] }}</a></td>
                        </tr>
                        <!-- RT_WEBSITE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_WEB_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 0 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="{{ $values['FIRMEN_WEBSITE_HREF'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['FIRMEN_WEBSITE_LABEL'] }}</a></td>
                        </tr>
                        <!-- RT_WEBSITE_END -->
                    </table>
                    {{-- Auf dem Telefon steht die Anschrift am Ende des
                         Personenblocks, damit nach dem Logo zuerst Name und
                         Kontakt kommen. Auf breiten Schirmen zeigt sie die
                         Markenspalte (rt-only-wide) ohne Zusatzhoehe. --}}
                    <div class="rt-only-narrow" style="display:none;max-height:0;overflow:hidden;mso-hide:all;margin:17px 0 0;font-size:0;line-height:0;color:{{ $values['SIGNATURE_META_TEXT'] }};"><strong style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};font-size:11px;line-height:18px;">{{ $values['FIRMENNAME'] }}</strong>@include('emails.parts.company-contact-table', ['values' => $values, 'align' => 'left'])</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
<tr>
    <td class="rt-pad" bgcolor="{{ $values['SIGNATURE_LEGAL_BG'] }}" style="padding:{{ $legalPadding }};background:{{ $values['SIGNATURE_LEGAL_BG'] }};color:{{ $values['SIGNATURE_LEGAL_TEXT'] }};font-size:9px;line-height:15px;">
        Geschäftsführung: {{ $values['GESCHAEFTSFUEHRUNG'] }} · Registergericht: {{ $values['REGISTERGERICHT'] }} · HRB {{ $values['HRB'] }} · USt-IdNr. {{ $values['UST_ID'] }} · Steuernummer {{ $values['STEUERNUMMER'] }}<br>
        Diese E-Mail kann vertrauliche Informationen enthalten. Sollten Sie nicht der vorgesehene Empfänger sein, informieren Sie bitte den Absender und löschen Sie diese Nachricht.
    </td>
</tr>
