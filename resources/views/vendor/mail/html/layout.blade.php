@php
    // Systemmails tragen dieselbe Schale wie die herunterladbare
    // E-Mail-Vorlage (resources/mail-templates/email-master.html): gleiche
    // Palette, gleiche Breite, gleiche mobile Umbruchregeln. Die Farbwerte
    // kommen aus derselben Quelle wie dort.
    $palette = \App\Support\EmailTemplateBuilder::emailThemeValues('light');
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
<meta name="color-scheme" content="{{ $palette['COLOR_SCHEME'] }}">
<meta name="supported-color-schemes" content="{{ $palette['COLOR_SCHEME'] }}">
<title>{{ config('app.name') }}</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
<style>
html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
table, td { border-collapse: collapse !important; mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; }
img { border: 0; display: block; height: auto; line-height: 100%; outline: none; text-decoration: none; }
a { color: inherit; }
.rt-shell { width: 100% !important; max-width: none !important; }
@media only screen and (max-width: 680px) {
.rt-pad { padding-left: 24px !important; padding-right: 24px !important; }
.rt-title { font-size: 27px !important; line-height: 32px !important; }
{{-- Nur direkte Zellen umbrechen: ein Nachfahren-Selektor wuerde auch die
     verschachtelte Kontakttabelle der Signatur zerlegen. --}}
tr.rt-stack > td { box-sizing: border-box !important; display: block !important; width: 100% !important; }
tr.rt-stack > td + td { padding-top: 12px !important; }
.rt-contact { width: auto !important; }
.rt-contact td.rt-contact-icon, .rt-contact td.rt-contact-text { display: table-cell !important; box-sizing: content-box !important; padding-top: 0 !important; }
.rt-contact td.rt-contact-icon { width: 22px !important; }
.rt-contact td.rt-contact-text { width: auto !important; font-size: 13px !important; line-height: 19px !important; }
.rt-sign-name { font-size: 22px !important; line-height: 26px !important; }
.rt-sign-logo { border-left: 0 !important; border-bottom: 1px solid {{ $palette['SIGNATURE_BORDER'] }} !important; padding: 0 0 17px !important; text-align: left !important; }
.rt-sign-logo img { width: 190px !important; margin-left: 0 !important; }
.rt-sign-identity { padding: 17px 0 0 !important; }
.rt-only-wide { display: none !important; max-height: 0 !important; overflow: hidden !important; }
.rt-only-narrow { display: block !important; max-height: none !important; overflow: visible !important; font-size: 11px !important; line-height: 18px !important; }
.rt-sign-cell { background-size: 125% auto !important; }
}
</style>
</head>
<body data-rt-theme="{{ $palette['THEME'] }}" bgcolor="{{ $palette['PAGE_BG'] }}" style="margin:0;padding:0;background:{{ $palette['PAGE_BG'] }};color:{{ $palette['TEXT_PRIMARY'] }};font-family:Arial,Helvetica,sans-serif;word-spacing:normal;">
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="{{ $palette['PAGE_BG'] }}" style="width:100%;background:{{ $palette['PAGE_BG'] }};">
<tr>
<td align="left" style="padding:0;">
<!--[if mso]><table role="presentation" width="100%"><tr><td><![endif]-->
<table class="rt-shell" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="{{ $palette['SURFACE_BG'] }}" style="width:100%;max-width:none;background:{{ $palette['SURFACE_BG'] }};">
<tr>
<td class="rt-pad" bgcolor="{{ $palette['SURFACE_BG'] }}" align="left" style="padding:42px 46px 34px;background:{{ $palette['SURFACE_BG'] }};font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:27px;color:{{ $palette['TEXT_SECONDARY'] }};text-align:left;">
{{-- Derselbe Auftakt wie in der Vorlage: kurzer Akzentstrich statt einer
     Linie ueber die ganze Breite. --}}
<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin:0 0 14px;">
<tr><td bgcolor="#e4002b" height="3" style="width:34px;height:3px;background:#e4002b;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
{{ Illuminate\Mail\Markdown::parse($slot) }}
{{ $subcopy ?? '' }}
</td>
</tr>
{{ $footer ?? '' }}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>
