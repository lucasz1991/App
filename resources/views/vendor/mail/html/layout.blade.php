<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml"
xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<title>{{ config('app.name') }}</title>
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="word-spacing:normal;background-color:rgba(220, 220, 220, 1);">
<div style="background-color:rgba(220, 220, 220, 1);">
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;">
<tbody>
<tr>
<td>
<div style="margin:0px auto;max-width:600px;">
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;">
<tbody>
<tr>
<td style="direction:ltr;font-size:0px;padding:50px 50px 35px 50px;text-align:center;">
<div class="mj-column-per-100 mj-outlook-group-fix"
style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation"
style="background-color:transparent;vertical-align:top;" width="100%">
<tbody>
<tr>
<td align="center" style="font-size:0px;padding:0px;word-break:break-word;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation"
style="border-collapse:collapse;border-spacing:0px;">
<tbody>
{{ $header ?? '' }}
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
</div>
</td>
</tr>
</tbody>
</table>
</div>
</td>
</tr>
</tbody>
</table>
<table align="center" style="width:100%;">
<tbody>
<tr>
<td>
<div style="margin:0px auto;max-width:600px;">
<div style="line-height:0;font-size:0;">
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation"
style="width:100%;">
<tbody>
<tr>
<td style="direction:ltr;font-size:0px;padding:0px 15px 0px 15px;text-align:center;">
<table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:600px;" >
<div class="mj-column-px-600 mj-outlook-group-fix"
style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%">
<tbody>
<tr>
<td
style="background-color:rgba(255, 255, 255, 1);border:0px solid rgba(255, 250, 250, 0.1);border-radius:25px 25px 25px 25px;vertical-align:top;padding:40px 20px 40px 20px;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation" style=""
width="100%">
<tbody>
{{ Illuminate\Mail\Markdown::parse($slot) }}
{{ $subcopy ?? '' }}
{{ $footer ?? '' }}
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
</div>
<div class="mj-column-per-100 mj-outlook-group-fix"
style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation"
style="vertical-align:top;" width="100%">
<tbody>
<tr>
<td align="center"
style="font-size:0px;padding:80px 0px 40px 0px;word-break:break-word;">
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation"
style="float:none;display:inline-table;">
<tbody>
<tr>
<td style="padding:4px;vertical-align:middle;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation"
style="background:#3f729b;border-radius:12px;width:24px;">
<tbody>
<tr>
<td style="font-size:0;height:24px;vertical-align:middle;width:24px;">
<a href="#"
target="_blank">
<img
 height="24" src="https://www.mailjet.com/images/theme/v1/icons/ico-social/instagram.png" style="border-radius:12px;display:block;" width="24"
/>
</a>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation"
style="float:none;display:inline-table;">
<tbody>
<tr>
<td style="padding:4px;vertical-align:middle;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation"
style="background:#3b5998;border-radius:12px;width:24px;">
<tbody>
<tr>
<td style="font-size:0;height:24px;vertical-align:middle;width:24px;">
<a href="#"
target="_blank">
<img
 height="24" src="https://www.mailjet.com/images/theme/v1/icons/ico-social/facebook.png" style="border-radius:12px;display:block;" width="24"
/>
</a>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
</div>
</td>
</tr>
</tbody>
</table>
</div>
</div>
</td>
</tr>
</tbody>
</table>
</div>
</body>
</html>