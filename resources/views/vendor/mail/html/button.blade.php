@props([
'url',
'color' => 'primary',
'align' => 'center',
])
<tr>
<td align="{{ $align }}" vertical-align="middle"
style="font-size:0px;padding:10px 25px;word-break:break-word;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation"
style="border-collapse:separate;line-height:100%;">
<tbody>
<tr>
<td align="{{ $align }}" bgcolor="#223d63" role="presentation"
style="border:none;border-radius:3px;cursor:auto;mso-padding-alt:10px 25px;background:#223d63;"
valign="middle">
<a href="{{ $url }}"
style="display:inline-block;background:#223d63;color:#ffffff;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;font-weight:normal;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:3px;"
target="_blank">
{{ $slot }}
</a>
</td>
</tr>
</tbody>
</table>
</td>
</tr>