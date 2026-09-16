<x-mail::message>
<div class="rt-title" style="margin:0 0 20px;color:#111820;font-family:Arial,Helvetica,sans-serif;font-size:30px;line-height:35px;font-weight:400;letter-spacing:-1px;">
@if (empty($greeting) || $greeting === 'default')
@if ($level === 'error')
{{ __('app.mail_greeting_error') }}
@else
{{ __('app.mail_greeting_default') }}
@endif
@else
{{ $greeting }}
@endif
</div>
{{-- Intro Lines --}}
@foreach ($introLines as $line)
<div style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;text-align:left;color:#3f4852;">{{ $line }}</div>
@endforeach

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

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
<div style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;text-align:left;color:#3f4852;">{{ $line }}</div>
@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
<div style="margin:6px 0 18px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#111820;">{{ $salutation }}</div>
@else
<div style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3f4852;">{{ __('app.mail_regards') }}</div>
<div style="margin:0 0 18px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;font-weight:bold;color:#111820;">{{ config('app.name') }}</div>
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('app.mail_subcopy', ['actionText' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
