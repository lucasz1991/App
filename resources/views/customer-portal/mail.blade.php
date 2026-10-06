<!doctype html>
<html lang="de"><head><meta charset="utf-8"></head><body>
    <h1>{{ $heading }}</h1>
    @if(!empty($messageContent['name']))<p>Guten Tag {{ $messageContent['name'] }},</p>@endif
    @if(!empty($messageContent['message']))<p>{{ $messageContent['message'] }}</p>@endif
    @if(!empty($messageContent['details']) && is_array($messageContent['details']))
        <ul>@foreach($messageContent['details'] as $detail)<li>{{ $detail }}</li>@endforeach</ul>
    @endif
    @if(!empty($messageContent['url']))<p><a href="{{ $messageContent['url'] }}">Kundenportal öffnen</a></p>@endif
    @if(!empty($messageContent['expires_at']))<p>Der Link ist zeitlich begrenzt.</p>@endif
    <p>RailTime</p>
</body></html>
