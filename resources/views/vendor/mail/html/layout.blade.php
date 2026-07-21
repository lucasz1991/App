<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
<title>{{ config('app.name') }}</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
<style>
html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
table, td { border-collapse: collapse !important; mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; }
img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
a { color: inherit; }
@media only screen and (max-width: 680px) {
.rt-pad { padding-left: 24px !important; padding-right: 24px !important; }
.rt-logo { width: 200px !important; }
.rt-title { font-size: 26px !important; line-height: 31px !important; }
.rt-stack, .rt-stack td { display: block !important; width: 100% !important; }
.rt-stack td + td { padding-top: 12px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#e7eaed;color:#111820;font-family:Arial,Helvetica,sans-serif;word-spacing:normal;">
<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#e7eaed" style="width:100%;background:#e7eaed;">
<tbody>
<tr>
<td align="center" style="padding:28px 12px;">
<!--[if mso]><table role="presentation" width="640" align="center"><tr><td><![endif]-->
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:640px;margin:0 auto;background:#f4f2ed;">
<tbody>
<tr>
<td class="rt-pad" bgcolor="#f4f2ed" align="left" style="padding:38px 44px 30px;background:#f4f2ed;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3f4852;text-align:left;">
{{ Illuminate\Mail\Markdown::parse($slot) }}
{{ $subcopy ?? '' }}
</td>
</tr>
{{ $footer ?? '' }}
</tbody>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</tbody>
</table>
</body>
</html>
