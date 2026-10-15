@php
    $call = $message->callRoom ? app(\App\Services\Calls\CallTimelineService::class)->projectionFor($message, $me) : null;
    $callStatus = $call ? ([
        'ringing' => 'Klingelt', 'active' => 'Läuft', 'ended' => 'Beendet',
        'missed' => 'Verpasst', 'declined' => 'Abgelehnt', 'cancelled' => 'Abgebrochen',
        'not_connected' => 'Nicht verbunden', 'failed' => 'Verbindung fehlgeschlagen', 'removed' => 'Teilnahme beendet',
    ][$call['status']] ?? '') : 'Nicht mehr verfügbar';
    $duration = $call['duration_seconds'] ?? null;
    $previous = $index > 0 ? $items[$index - 1] : null;
@endphp
@if(!$previous || !$previous->created_at->isSameDay($message->created_at))
    <div class="flex justify-center py-3"><span class="rt-chat-date-chip rounded-full px-3 py-1 text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">{{ $message->created_at->isToday() ? __('app.today') : ($message->created_at->isYesterday() ? __('app.yesterday') : $message->created_at->format('d.m.Y')) }}</span></div>
@endif
<div wire:key="chat-call-{{ $message->id }}" data-chat-call-event="{{ $message->id }}" class="rt-chat-call-event my-3 flex items-center gap-3 rounded-xl border border-rt-border bg-rt-surface p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface">
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted">
        <i class="far {{ ($call['medium'] ?? 'audio') === 'audio' ? 'fa-phone' : 'fa-video' }}" aria-hidden="true"></i>
    </span>
    <div class="min-w-0 flex-1">
        <p class="text-xs font-bold">{{ ($call['medium'] ?? 'audio') === 'meeting' ? 'Meeting' : (($call['medium'] ?? 'audio') === 'video' ? 'Videoanruf' : 'Sprachanruf') }}</p>
        <p class="mt-1 text-[10px] text-rt-muted dark:text-rt-dark-muted">
            {{ $message->created_at->format('H:i') }} · {{ $callStatus }}
            @if($duration !== null) · {{ gmdate($duration >= 3600 ? 'H:i:s' : 'i:s', (int)$duration) }} @endif
        </p>
    </div>
    @if($call && $call['can_join'])
        <a href="{{ route('calls.window', ['room' => $call['room_uuid']]) }}" class="shrink-0 text-xs font-semibold text-rt-red">Beitreten</a>
    @elseif($call && in_array($call['status'], ['ended','missed','declined','cancelled','not_connected','failed']) && ($me->isAdmin() || $me->hasRbacPermission('calls.start')))
        <button type="button" wire:click="startCall({{ $call['medium'] !== 'audio' ? 'true' : 'false' }})" wire:loading.attr="disabled" data-no-chat-swipe class="shrink-0 text-xs font-semibold text-rt-red">Rückruf</button>
    @endif
    @if($call && $call['can_open_history'])
        <a href="{{ route('calls.history', ['room' => $call['room_uuid']]) }}" class="rt-chat-icon-button shrink-0" aria-label="Anrufdetails" title="Anrufdetails"><i class="far fa-info-circle" aria-hidden="true"></i></a>
    @endif
</div>
