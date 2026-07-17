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
<div class="flex items-center gap-2 px-2 py-2 pr-4 {{ $hc(0) }}">
    <img src="{{ $senderAvatar }}" class="h-6 w-6 rounded-full object-cover" alt="">
    <div class="truncate">
        <span class="text-gray-900 dark:text-slate-100 {{ $isUnread ? 'font-semibold' : 'font-medium' }}">{{ $senderName }}</span>
        @if ($isUnread)
            <span class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-blue-500 align-middle" aria-hidden="true"></span>
        @endif
    </div>
</div>

{{-- 1: Betreff --}}
<div class="flex min-w-0 flex-col px-2 py-2 {{ $hc(1) }}">
    <div class="truncate text-gray-900 dark:text-slate-100 {{ $isUnread ? 'font-semibold' : 'font-medium' }}">
        {{ $item->subject }}
    </div>
</div>

{{-- 2: Nachricht (Snippet + ggf. Anhang) --}}
<div class="grid min-w-0 grid-cols-[auto_1fr] items-center gap-2 px-2 py-2 text-gray-700 dark:text-slate-300 {{ $hc(2) }}">
    @if (($item->files_count ?? 0) > 0)
        <i class="far fa-paperclip text-gray-500 dark:text-slate-400" aria-hidden="true"></i>
    @endif
    <div class="flex min-w-0 flex-col">
        <span class="truncate">{{ $snippet }}</span>
    </div>
</div>

{{-- 3: Datum --}}
<div class="px-2 py-2 text-xs text-gray-600 dark:text-slate-400 {{ $hc(3) }}" title="{{ $createdAbs }}">
    <div>
        {{ $createdRel }}
    </div>
</div>
