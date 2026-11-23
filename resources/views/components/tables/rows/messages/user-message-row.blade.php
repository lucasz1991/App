{{-- Nachrichten eines Nutzers im Admin-Profil (UserMessages). Klick oeffnet showMessage. --}}
<x-tables.cell :column="$columnsMeta[0]" :primary="true">
    <span class="block truncate text-sm font-medium text-rt-text dark:text-white">
        @if ((int) $item->status === 1)
            <span class="mr-1 inline-block h-2 w-2 rounded-full bg-rt-red align-middle" title="{{ __('app.unread') }}"></span>
        @endif
        {{ $item->subject }}
    </span>
    <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.from') }}: {{ $item->sender?->name ?? __('app.unknown') }}</span>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[1]">
    <span class="text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $item->created_at?->format('d.m.Y H:i') }}</span>
</x-tables.cell>
