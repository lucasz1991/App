{{--
    Die Firmenanschluesse als Symbol-Text-Paare.

    GESPIEGELT: Bei align="right" steht das Symbol RECHTS vom Text. Nur so
    schliessen beide Spalten der Signatur nach aussen buendig ab — die
    Personenspalte mit den Symbolen links, die Firmenspalte mit den
    Symbolen rechts. Ohne die Spiegelung stuenden rechts die Symbole
    mitten in der Spalte und die Achse waere zerbrochen.

    @param array  $values          Werte- und Farbtabelle (siehe MailSignature)
    @param string $align           'left' oder 'right'
    @param bool   $ohneDoppelung   Telefon und E-Mail weglassen, weil sie im
                                   unpersoenlichen Fall bereits links stehen
--}}
@php
    $spiegeln = ($align ?? 'left') === 'right';
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
            'marker' => null,
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

    $letzte = count($zeilen) - 1;
@endphp
<table class="rt-contact rt-company-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:{{ $spiegeln ? 'auto' : '0' }};margin-right:{{ $spiegeln ? '0' : 'auto' }};margin-top:21px;border-collapse:collapse;">
    @foreach($zeilen as $index => $zeile)
        @php $unten = $index === $letzte ? '0' : '6px'; @endphp
        @if($zeile['marker'])<!-- RT_{{ $zeile['marker'] }}_START -->@endif
        <tr>
            @unless($spiegeln)
                <td width="22" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:22px;padding:0 0 {{ $unten }};font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values[$zeile['icon']] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
            @endunless
            <td valign="middle" class="rt-contact-text rt-company-contact-text" style="padding:0 {{ $spiegeln ? '9px' : '0' }} {{ $unten }} {{ $spiegeln ? '0' : '9px' }};color:{{ $values['SIGNATURE_META_TEXT'] }};font-size:12px;line-height:18px;text-align:{{ $spiegeln ? 'right' : 'left' }};">@if($zeile['href'] !== '')<a href="{{ $zeile['href'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $zeile['text'] }}</a>@else{{ $zeile['text'] }}@endif</td>
            @if($spiegeln)
                <td width="22" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:22px;padding:0 0 {{ $unten }};font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values[$zeile['icon']] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
            @endif
        </tr>
        @if($zeile['marker'])<!-- RT_{{ $zeile['marker'] }}_END -->@endif
    @endforeach
</table>
