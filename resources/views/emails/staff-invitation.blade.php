{{--
    Einladung zur Mitarbeit — seit der Vereinheitlichung im gemeinsamen
    RailTime-Mail-Layout. Dadurch traegt sie dieselbe Schale, dieselbe
    Palette und dieselbe Signatur wie jede andere Systemmail und wie die
    herunterladbare Vorlage. Frueher brachte diese Datei ein eigenes
    Kartenlayout samt eigener Fusszeile mit.
--}}
<x-mail::message>
<div style="margin:0 0 10px;color:#e4002b;font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.4px;text-transform:uppercase;">RT / {{ config('app.name') }}</div>
<div class="rt-title" style="margin:0 0 20px;color:#111820;font-family:Arial,Helvetica,sans-serif;font-size:30px;line-height:35px;font-weight:400;letter-spacing:-1px;">
{{ __('app.you_were_invited', ['app' => config('app.name')]) }}
</div>

<div style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3f4852;">
{{ __('app.invitation_body', [
    'inviter' => $invitation->inviter?->name ?? __('app.the_administration'),
    'app' => config('app.name'),
]) }}
({{ __('app.role') }}: <strong style="color:#111820;">{{ $invitation->role === 'admin' ? __('app.role_admin') : __('app.role_staff') }}</strong>).
</div>

<div style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#3f4852;">
{{ __('app.invitation_click_button') }}
</div>

<x-mail::button :url="$registrationUrl">
{{ __('app.complete_registration') }}
</x-mail::button>

@if ($invitation->expires_at)
<div style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:21px;color:#89939e;">
{{ __('app.invitation_valid_until', ['date' => $invitation->expires_at->format('d.m.Y H:i')]) }}
</div>
@endif

<x-slot:subcopy>
{{ __('app.invitation_link_fallback') }} <span class="break-all">[{{ $registrationUrl }}]({{ $registrationUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
