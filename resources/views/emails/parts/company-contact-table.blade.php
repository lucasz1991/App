{{--
    Die Firmenanschluesse als Symbol-Text-Paare.

    Alle Anschluesse stehen in EINER Tabelle und jeder Wert existiert genau
    einmal im DOM. Das ist absichtlich schlichter als zwei umschaltbare
    Desktop-/Mobilfassungen: Beim Antworten oder Weiterleiten entfernen
    Mailclients haeufig Media-Queries, nicht aber das zuvor versteckte Markup.

    Die Reihenfolge bleibt deshalb ueberall identisch und mailclient-sicher:
    Symbol links, Text rechts. Der umgebende, erlaubte `div[align]` und die
    Tabellenraender positionieren den Block im Breitlayout robust rechts;
    die mobile CSS-Regel setzt denselben Block links, ohne Zellen umzuordnen.

    @param array  $values          Werte- und Farbtabelle (siehe MailSignature)
    @param string $align           'left' oder 'right'
    @param bool   $ohneDoppelung   Telefon und E-Mail weglassen, weil sie im
                                   unpersoenlichen Fall bereits links stehen
--}}
@php
    $rechtsPositioniert = ($align ?? 'left') === 'right';
    $ohneDoppelung = $ohneDoppelung ?? false;

    // Strasse und Ort in EINER Zeile: der Umbruch kostete eine ganze
    // Zeilenhoehe, ohne mehr zu sagen. Reicht der Platz nicht, bricht der
    // Client an derselben Stelle von selbst um.
    $anschrift = trim($values['FIRMENSTRASSE'].' · '.$values['FIRMEN_PLZ_ORT'], ' ·');

    $zeilen = array_values(array_filter([
        ['marker' => null, 'icon' => 'ICON_LOCATION_SRC', 'href' => '', 'text' => $anschrift],
        $ohneDoppelung ? null : [
            'marker' => 'COMPANY_PHONE',
            'icon' => 'ICON_PHONE_SRC',
            'href' => 'tel:'.$values['FIRMEN_TELEFON_TEL'],
            'text' => $values['FIRMEN_TELEFON'],
        ],
        $ohneDoppelung ? null : [
            'marker' => 'COMPANY_EMAIL',
            'icon' => 'ICON_EMAIL_SRC',
            'href' => 'mailto:'.$values['FIRMEN_EMAIL'],
            'text' => $values['FIRMEN_EMAIL'],
        ],
        [
            'marker' => 'WEBSITE',
            'icon' => 'ICON_WEB_SRC',
            'href' => $values['FIRMEN_WEBSITE_HREF'],
            'text' => $values['FIRMEN_WEBSITE_LABEL'],
        ],
    ]));
@endphp
@php $letzte = count($zeilen) - 1; @endphp
<table class="rt-contact rt-company-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:{{ $rechtsPositioniert ? 'auto' : '0' }};margin-right:{{ $rechtsPositioniert ? '0' : 'auto' }};margin-top:26px;border-collapse:collapse;">
    <tbody>
    @foreach($zeilen as $index => $zeile)
        @php $unten = $index === $letzte ? '0' : '6px'; @endphp
        @if($zeile['marker'])<!-- RT_{{ $zeile['marker'] }}_START -->@endif
        <tr>
            <td width="22" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:22px;padding:0 0 {{ $unten }};font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values[$zeile['icon']] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
            <td valign="middle" align="left" class="rt-contact-text rt-company-contact-text" style="padding:0 0 {{ $unten }} 9px;color:{{ $values['SIGNATURE_META_TEXT'] }};font-size:12px;line-height:18px;text-align:left;">@if($zeile['href'] !== '')<a href="{{ $zeile['href'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $zeile['text'] }}</a>@else{{ $zeile['text'] }}@endif</td>
        </tr>
        @if($zeile['marker'])<!-- RT_{{ $zeile['marker'] }}_END -->@endif
    @endforeach
    </tbody>
</table>
