{{-- Aktivitätsliste des Dispositions-Assistenten (AI-Assist und zentraler Chat), nach Tagen gruppiert. --}}
@props([
    'items',
    'empty' => 'Noch keine Aktivitäten.',
    'navigate' => false,
    'keyPrefix' => 'ai-activity',
])
@if(collect($items)->isEmpty())
    <p class="rt-ai-assist__note">{{ $empty }}</p>
@else
    @php
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $today = \Carbon\CarbonImmutable::now($zone)->toDateString();
        $yesterday = \Carbon\CarbonImmutable::now($zone)->subDay()->toDateString();
        $group = null;
    @endphp
    <ol {{ $attributes->class('rt-ai-assist__activity') }}>
        @foreach($items as $item)
            @php
                $local = $item['at']->setTimezone($zone);
                $day = $local->toDateString() === $today ? 'Heute' : ($local->toDateString() === $yesterday ? 'Gestern' : $local->format('d.m.Y'));
            @endphp
            @if($day !== $group)
                @php
                    $group = $day;
                @endphp
                <li class="rt-ai-assist__activity-group">{{ $day }}</li>
            @endif
            <li wire:key="{{ $keyPrefix }}-{{ $item['id'] ?? sha1($item['kind'].'|'.$item['title'].'|'.$item['at']->toIso8601String()) }}">
                @if($item['href'])<a class="rt-ai-assist__activity-item" href="{{ $item['href'] }}" @if($navigate) wire:navigate @endif>@else<div class="rt-ai-assist__activity-item">@endif
                    <span class="rt-ai-assist__activity-icon" data-tone="{{ $item['tone'] }}"><i class="far {{ $item['icon'] }}" aria-hidden="true"></i></span>
                    <span class="rt-ai-assist__activity-text"><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small>@if($item['intake_id'] ?? null)<small>AI-Eingang #{{ $item['intake_id'] }}</small>@endif</span>
                    <span class="rt-ai-assist__activity-side"><time datetime="{{ $local->toIso8601String() }}">{{ $local->format('H:i') }}</time><span class="rt-ai-assist__badge" data-tone="{{ $item['tone'] }}">{{ $item['status'] }}</span></span>
                @if($item['href'])</a>@else</div>@endif
            </li>
        @endforeach
    </ol>
@endif
