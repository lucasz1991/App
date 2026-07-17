<x-mail::message>
@if (! empty($greeting))
<tr>
<td align="center" style="font-size:0px;padding:10px 25px;word-break:break-word; font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:24px;font-weight:bold;line-height:1;text-align:center;color:#0c968e;">
@if ($greeting === 'default')
@if ($level === 'error')
@lang('Whoops!')
@else
@lang('Hello!')
@endif
@else
{{ $greeting }}
@endif
</td>
</tr>
@endif

<tr>
<td align="left" style="font-size:0px;padding:10px 25px 30px 25px;word-break:break-word;">
{{-- Intro Lines --}}
@foreach ($introLines as $line)
<div style="font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;line-height:1.5;text-align:left;color:#000000;">
{{ $line }}
</div>
@endforeach
</td>
</tr>

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

<tr>
<td align="left" style="font-size:0px;padding:10px 25px 30px 25px;word-break:break-word;">
{{-- Outro Lines --}}
@foreach ($outroLines as $line)
<div style="font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;line-height:1.5;text-align:left;color:#000000;">
{{ $line }}
</div>
@endforeach
</td>
</tr>

{{-- Salutation --}}
@if (! empty($salutation))
<tr>
<td align="left" style="font-size:0px;padding:10px 25px 30px 25px;word-break:break-word;">
<div style="font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;line-height:1.5;text-align:left;color:#000000;">
{{ $salutation }}
</div>
</td>
</tr>
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "Wenn du Probleme hast, den \":actionText\"-Button zu klicken, kopiere bitte die folgende URL und füge sie\n".
    'in deinen Webbrowser ein:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
