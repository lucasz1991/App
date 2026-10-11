@props(['seats', 'summary', 'expanded' => false, 'timezone' => 'Europe/Berlin'])
@php
    $open = max(0, (int) $summary['open']);
    $staffingLabel = $summary['reserved'].' von '.$summary['required'].' Einsatzplätzen eingeplant, '.$summary['confirmed'].' bestätigt, '.$open.' offen';
    $viewer = auth()->user();
    $canViewProfiles = $viewer?->canViewManagementDashboard() && $viewer->can('employees.view');
@endphp
<div class="rt-service-staffing__content" @if($expanded) data-service-staffing-preview-content x-data="{ activeSeat: null }" x-effect="if (!open) activeSeat = null" @endif>
    <div class="rt-service-staffing__caption">
        <span class="tabular-nums" aria-label="{{ $staffingLabel }}">{{ $summary['reserved'] }}/{{ $summary['required'] }}</span>
        @if($open > 0)
            <span class="rt-service-staffing__open">{{ $open }} {{ $open === 1 ? 'Platz offen' : 'Plätze offen' }}</span>
        @endif
    </div>
    @if($seats->isNotEmpty() || $open > 0)
        <div class="rt-service-avatars" role="group" aria-label="{{ $staffingLabel }}" tabindex="0">
            @if($seats->isNotEmpty())
                <div class="rt-service-avatar-group" @if($expanded) data-service-preview-assigned-group @else data-service-assigned-group @endif role="group" aria-label="{{ $seats->count() }} belegte Einsatzplätze">
                    @foreach($seats as $seat)
                        @php
                            $user = $seat['user'];
                            $name = $user?->name ?: 'Eingeplante Person nicht verfügbar';
                            $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('') ?: '?';
                            $seatLabel = $name.' · '.$seat['shift'];
                        @endphp
                        <span class="rt-service-avatar" aria-label="{{ $seatLabel }}" @if($expanded) data-service-preview-person="{{ $user?->id }}" data-service-preview-seat="{{ $seat['id'] }}" role="button" tabindex="0" aria-controls="service-seat-detail-{{ $seat['id'] }}" :aria-expanded="(activeSeat === {{ $seat['id'] }}).toString()" data-rt-dropdown-keep-open @pointerenter="if ($event.pointerType === 'mouse') activeSeat = {{ $seat['id'] }}" @focus="activeSeat = {{ $seat['id'] }}" @click.stop="activeSeat = {{ $seat['id'] }}" @keydown.enter.prevent.stop="activeSeat = {{ $seat['id'] }}" @keydown.space.prevent.stop="activeSeat = {{ $seat['id'] }}" @else title="{{ $seatLabel }}" data-service-person="{{ $user?->id }}" data-service-seat="{{ $seat['id'] }}" @endif>
                            @if($user?->profile_photo_path)
                                <img src="{{ $user->profile_photo_url }}" alt="" loading="lazy" onerror="this.hidden=true" />
                            @endif
                            <span aria-hidden="true">{{ $user ? $initials : '?' }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
            @if($open > 0)
                <div class="rt-service-avatar-group rt-service-avatar-group--open" @if($expanded) data-service-preview-open-group @else data-service-open-group @endif role="group" aria-label="{{ $open }} offene Einsatzplätze">
                    @for($slot = 0; $slot < $open; $slot++)
                        <span class="rt-service-avatar rt-service-avatar--open" title="Offener Einsatzplatz {{ $slot + 1 }} von {{ $open }}" aria-label="Offener Einsatzplatz {{ $slot + 1 }} von {{ $open }}" @if($expanded) data-service-preview-open-slot @else data-service-open-slot @endif><span aria-hidden="true">?</span><span class="rt-service-avatar__dot" aria-hidden="true"></span></span>
                    @endfor
                </div>
            @endif
        </div>
    @endif
    @if($expanded && $seats->isNotEmpty())
        <div class="rt-service-person-details" aria-live="polite" aria-atomic="true" x-show="activeSeat !== null" x-cloak>
            @foreach($seats as $seat)
                @php
                    $user = $seat['user'];
                    $name = $user?->name ?: 'Eingeplante Person nicht verfügbar';
                    $from = ($seat['starts_at'] ?? null)?->copy()->setTimezone($timezone);
                    $until = ($seat['ends_at'] ?? null)?->copy()->setTimezone($timezone);
                @endphp
                <div id="service-seat-detail-{{ $seat['id'] }}" class="rt-service-person-detail" data-service-person-detail="{{ $seat['id'] }}" x-show="activeSeat === {{ $seat['id'] }}" x-transition:enter="rt-service-person-enter" x-transition:enter-start="rt-service-person-enter-from" x-transition:enter-end="rt-service-person-enter-to" x-cloak>
                    @if($user)
                        <div class="rt-service-person-detail__body">
                            @if($canViewProfiles)
                                <a href="{{ route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $user->id) }}" wire:navigate class="rt-service-person-detail__link" aria-label="Profil öffnen: {{ $name }}" data-service-person-profile="{{ $user->id }}" data-table-row-ignore>
                                    <x-user.public-info :user="$user" :size="10" :show-email="false" :show-presence="false" :local-initials="true" />
                                </a>
                            @else
                                <x-user.public-info :user="$user" :size="10" :show-email="false" :show-presence="false" :local-initials="true" />
                            @endif
                            <div class="rt-service-person-detail__shift"><span><i class="far fa-calendar-day" aria-hidden="true"></i> {{ $seat['shift'] ?: 'Schicht' }}</span>@if($from && $until)<time datetime="{{ $from->toIso8601String() }}">{{ $from->format('d.m.Y H:i') }} – {{ $until->format('d.m.Y H:i') }}</time>@endif</div>
                        </div>
                    @else
                    <span class="rt-service-person-detail__avatar" aria-hidden="true">
                        <span>?</span>
                    </span>
                    <div><strong>{{ $name }}</strong><span><i class="far fa-calendar-day" aria-hidden="true"></i> {{ $seat['shift'] ?: 'Schicht' }}</span>@if($from && $until)<time datetime="{{ $from->toIso8601String() }}">{{ $from->format('d.m.Y H:i') }} – {{ $until->format('d.m.Y H:i') }}</time>@endif</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
