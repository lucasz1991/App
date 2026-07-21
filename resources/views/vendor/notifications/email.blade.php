<x-mail::message>
{{-- Eyebrow im d1-Stil --}}
<tr>
<td align="left" style="padding:0 0 10px;">
<div style="color:#e4002b;font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.4px;text-transform:uppercase;">RT / {{ config('app.name') }}</div>
</td>
</tr>

@if (! empty($greeting))
<tr>
<td align="left" style="padding:0 0 20px;">
<div class="rt-title" style="color:#111820;font-family:Arial,Helvetica,sans-serif;font-size:30px;line-height:35px;font-weight:400;letter-spacing:-1px;">
@if ($greeting === 'default')
@if ($level === 'error')
@lang('Whoops!')
@else
@lang('Hallo!')
@endif
@else
{{ $greeting }}
@endif
</div>
</td>
</tr>
@endif

<tr>
<td align="left" style="padding:0 0 14px;">
{{-- Intro Lines --}}
@foreach ($introLines as $line)
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;text-align:left;color:#3f4852;margin:0 0 12px;">
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
<td align="left" style="padding:0 0 10px;">
{{-- Outro Lines --}}
@foreach ($outroLines as $line)
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;text-align:left;color:#3f4852;margin:0 0 12px;">
{{ $line }}
</div>
@endforeach
</td>
</tr>

{{-- Salutation --}}
<tr>
<td align="left" style="padding:0 0 18px;">
@if (! empty($salutation))
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#111820;">{{ $salutation }}</div>
@else
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3f4852;">@lang('Freundliche Grüße')</div>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;font-weight:bold;color:#111820;">{{ config('app.name') }}</div>
@endif
</td>
</tr>

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
