@php
    $isUnread = (int) $item->status === 1;
    $isAdminSender = $item->sender?->role === 'admin';
    $senderName = $isAdminSender ? config('app.name') : ($item->sender?->name ?? __('app.unknown'));
    $senderAvatar = $isAdminSender ? asset('rt-brand/rt-logo.svg') : ($item->sender?->profile_photo_url ?? asset('rt-brand/rt-logo.svg'));
    $snippet = \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($item->message), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 180);
@endphp
<div role="cell" class="rt-message-inbox__sender">
    <button type="button" role="checkbox" aria-checked="{{ $isSelected ? 'true' : 'false' }}" aria-label="Nachricht {{ $item->subject }} {{ $isSelected ? 'abwählen' : 'auswählen' }}"
        class="rt-message-inbox__checkbox" wire:click="toggleMessageSelection({{ $item->id }})" x-on:keydown.enter.stop wire:loading.attr="disabled">
        <span aria-hidden="true">@if($isSelected)<i class="far fa-check"></i>@endif</span>
    </button>
    <img class="rt-message-inbox__avatar" src="{{ $senderAvatar }}" alt="" loading="lazy">
    <span class="rt-message-inbox__sender-name" title="{{ $senderName }}">{{ $senderName }}</span>
</div>
<div role="cell" class="rt-message-inbox__message" data-message-unread="{{ $isUnread ? 'true' : 'false' }}">
    <button type="button" class="rt-message-inbox__subject" wire:click="openMessageDetail({{ $item->id }})" x-on:keydown.enter.stop aria-label="Nachricht öffnen: {{ $item->subject }}">
        @if($isUnread)<span class="rt-message-inbox__status-dot" aria-hidden="true"></span>@endif
        <span>{{ $item->subject ?: 'Ohne Betreff' }}</span>
        <span class="sr-only">{{ $isUnread ? 'Ungelesen' : 'Gelesen' }}</span>
    </button>
    <p class="rt-message-inbox__snippet">{{ $snippet }}</p>
    <div class="rt-message-inbox__message-meta">
        <time class="rt-message-inbox__mobile-date" datetime="{{ $item->created_at?->toIso8601String() }}">{{ $item->created_at?->format('d.m.Y · H:i') }}</time>
        @if($item->files_count > 0)
            <span class="rt-message-inbox__attachment"><i class="far fa-paperclip" aria-hidden="true"></i>{{ $item->files_count }} {{ $item->files_count === 1 ? 'Anhang' : 'Anhänge' }}</span>
        @endif
    </div>
</div>
<div role="cell" class="rt-message-inbox__date hidden md:block">
    <time datetime="{{ $item->created_at?->toIso8601String() }}" title="{{ $item->created_at?->format('d.m.Y H:i') }}">
        <span>{{ $item->created_at?->format('d.m.Y') ?? '–' }}</span><small>{{ $item->created_at?->format('H:i') }}</small>
    </time>
</div>
