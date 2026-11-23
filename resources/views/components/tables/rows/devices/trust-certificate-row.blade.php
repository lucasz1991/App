@php
    $hc = fn ($index) => $hideClass($columnsMeta[$index]['hideOn'] ?? 'none');

    /** @var \App\Models\DeviceTrustCertificate $item */
    $expired = $item->valid_until?->isPast();
    $soon = ! $expired && $item->valid_until?->lt(now()->addDays(60));
    $chip = 'inline-flex max-w-full items-center rounded-md px-2 py-0.5 text-[11px] font-semibold';
    $lists = $item->documentVersions->map(fn ($version) => trim(($version->document?->title ?? 'Datei entfernt').' · v'.$version->version_number));
@endphp

<div data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(0) }}">
    <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $item->name }}</span>
    <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->self_signed ? 'Selbst erstellt' : 'Von einer Zertifizierungsstelle' }}</span>
    @unless($item->active)
        <span class="{{ $chip }} mt-1 bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300">Deaktiviert</span>
    @endunless
</div>
<div data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(1) }}">
    <span class="block break-words font-mono text-xs text-rt-text dark:text-white">{{ $item->thumbprint_label }}</span>
    <span class="mt-0.5 block text-xs text-rt-muted dark:text-rt-dark-muted">SHA-1, wie Windows ihn anzeigt</span>
</div>
<div data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}" class="min-w-0 px-2 py-2 text-sm {{ $hc(2) }}">
    <span class="block text-rt-text dark:text-white">{{ $item->valid_until?->format('d.m.Y') }}</span>
    @if($expired)
        <span class="{{ $chip }} mt-1 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300">Abgelaufen</span>
    @elseif($soon)
        <span class="{{ $chip }} mt-1 bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">Läuft bald ab</span>
    @endif
</div>
<div data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(3) }}">
    @forelse($lists->take(4) as $list)
        <span class="{{ $chip }} mb-1 mr-1 break-words bg-rt-surface-muted text-rt-text ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">{{ $list }}</span>
    @empty
        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">Noch mit keiner Dateiversion verbunden</span>
    @endforelse
    @if($lists->count() > 4)
        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">und {{ $lists->count() - 4 }} weitere</span>
    @endif
</div>
