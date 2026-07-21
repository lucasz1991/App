@php
    $hc = fn ($i) => $hideClass($columnsMeta[$i]['hideOn'] ?? 'none');

    $isAdminSender = optional($item->sender)->role === 'admin';
    $senderName    = $isAdminSender ? config('app.name') : ($item->sender->name ?? __('app.unknown'));
    $senderAvatar  = $isAdminSender
        ? asset('rt-brand/rt-logo.svg')
        : ($item->sender?->profile_photo_url ?? asset('rt-brand/rt-logo.svg'));

    $isUnread = (int) $item->status === 1;

    $createdAbs = optional($item->created_at)->format('d.m.Y H:i');
    $createdRel = optional($item->created_at)?->diffForHumans();

    $snippet = \Illuminate\Support\Str::limit(strip_tags($item->message), 100);
@endphp

{{-- 0: Von --}}
<div class="col-span-2 flex min-w-0 items-center gap-2 px-2 pb-1 pt-2 pr-10 md:col-span-1 md:py-2 md:pr-4 {{ $hc(0) }}">
    <img src="{{ $senderAvatar }}" class="h-6 w-6 rounded-full object-cover" alt="">
    <div class="truncate">
        <span class="text-rt-text dark:text-rt-dark-text {{ $isUnread ? 'font-semibold' : 'font-medium' }}">{{ $senderName }}</span>
        @if ($isUnread)
            <span class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-rt-red align-middle" aria-hidden="true"></span>
        @endif
    </div>
</div>

{{-- 1: Betreff --}}
<div class="col-span-2 flex min-w-0 flex-col px-2 py-1 md:col-span-1 md:py-2 {{ $hc(1) }}">
    <button
        type="button"
        wire:click="$dispatch('message-viewer:open', { messageId: {{ $item->id }} })"
        class="truncate text-left text-rt-text transition-colors duration-200 hover:text-rt-red focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-text dark:hover:text-rt-dark-accent {{ $isUnread ? 'font-semibold' : 'font-medium' }}"
    >
        {{ $item->subject }}
    </button>
</div>

{{-- 2: Nachricht (Snippet + ggf. Anhang) --}}
<div class="col-span-2 grid min-w-0 grid-cols-[auto_1fr] items-center gap-2 px-2 pb-2 pt-1 text-xs text-rt-muted md:col-span-1 md:py-2 md:text-sm dark:text-rt-dark-muted {{ $hc(2) }}">
    @if (($item->files_count ?? 0) > 0)
        <i class="far fa-paperclip text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
    @endif
    <div class="flex min-w-0 flex-col">
        <span class="truncate">{{ $snippet }}</span>
    </div>
</div>

{{-- 3: Datum --}}
<div class="col-span-2 px-2 py-2 text-xs text-rt-muted md:col-span-1 dark:text-rt-dark-muted {{ $hc(3) }}" title="{{ $createdAbs }}">
    <div>
        {{ $createdRel }}
    </div>
</div>
