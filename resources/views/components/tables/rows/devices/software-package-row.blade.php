@php
    $hc = fn ($index) => $hideClass($columnsMeta[$index]['hideOn'] ?? 'none');

    /** @var \App\Models\DeviceSoftwarePackage $item */
    $targets = (array) ($item->target_labels ?? []);
    $reported = (int) ($item->reported_count ?? 0);
    $installed = (int) ($item->installed_count ?? 0);
    $chip = 'inline-flex max-w-full items-center rounded-md px-2 py-0.5 text-[11px] font-semibold';
@endphp

<div data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(0) }}">
    <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $item->name }}</span>
    <span class="mt-0.5 block truncate font-mono text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->package_id }}</span>
    @unless($item->active)
        <span class="{{ $chip }} mt-1 bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300">Deaktiviert</span>
    @endunless
</div>
<div data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}" class="min-w-0 px-2 py-2 text-sm {{ $hc(1) }}">
    <span class="block font-mono text-rt-text dark:text-white">{{ $item->version }}</span>
    <span class="mt-0.5 block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->scope === 'machine' ? 'Für das ganze Gerät' : 'Für den Benutzer' }}</span>
</div>
<div data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(2) }}">
    @forelse($targets as $target)
        <span class="{{ $chip }} mb-1 mr-1 break-words bg-rt-surface-muted text-rt-text ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">{{ $target }}</span>
    @empty
        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">Noch nicht zugewiesen</span>
    @endforelse
</div>
<div data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}" class="min-w-0 px-2 py-2 text-sm {{ $hc(3) }}">
    @if($reported > 0)
        <span class="font-semibold text-rt-text dark:text-white">{{ $installed }} von {{ $reported }} installiert</span>
        <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">nach Meldung der Geräte</span>
    @else
        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">Noch keine Meldung</span>
    @endif
</div>
