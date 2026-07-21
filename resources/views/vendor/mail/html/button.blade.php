@props([
'url',
'color' => 'primary',
'align' => 'left',
])
<?php
    $background = match ($color) {
        'success' => '#157f66',
        'error' => '#9f0020',
        default => '#e4002b',
    };
?>
<tr>
<td align="{{ $align }}" style="padding:8px 0 18px;">
<table role="presentation" border="0" cellpadding="0" cellspacing="0">
<tbody>
<tr>
<td bgcolor="{{ $background }}" style="background:{{ $background }};">
<a href="{{ $url }}" target="_blank" style="display:inline-block;padding:14px 22px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:18px;font-weight:bold;text-decoration:none;text-transform:uppercase;letter-spacing:.5px;">{{ $slot }} &nbsp;&rarr;</a>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
