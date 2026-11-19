@php
    $hc = fn ($index) => $hideClass($columnsMeta[$index]['hideOn'] ?? 'none');

    /** @var \App\Models\DeviceDesktopSoftwareState $item */
    $device = $item->client?->device;
    $job = $item->relationLoaded('lastJob') ? $item->getRelation('lastJob') : null;
    $open = $job && in_array($job->status, ['queued', 'offered'], true);
    $stopped = ! $item->installed && $item->attempts >= (int) ($item->max_attempts ?? 3) && ! $open;
    $chip = 'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold';
    [$stateLabel, $stateClass] = match (true) {
        (bool) $item->installed => ['Installiert', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        $open => ['Installation läuft', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        $stopped => ['Angehalten', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
        default => ['Fehlt', 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200'],
    };
    $jobLabels = ['queued' => 'Vorgemerkt', 'offered' => 'An das Gerät übergeben', 'expired' => 'Ohne Rückmeldung abgelaufen',
        'cancelled' => 'Abgebrochen', 'succeeded' => 'Gerät meldet Erfolg', 'failed' => 'Gerät meldet Fehler',
        'declined' => 'Am Gerät abgelehnt', 'unsupported' => 'Vom Gerät nicht unterstützt', 'requires_admin' => 'Adminrechte nötig'];
    $result = $job?->result ?? [];
    $exitCode = $result['exit_code'] ?? null;
@endphp

<div data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(0) }}">
    <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $device?->display_name ?: $device?->hostname ?: 'Gerät nicht verfügbar' }}</span>
    <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->client?->assignment?->user?->name ?? 'Keine Zuweisung' }}</span>
</div>
<div data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(1) }}">
    <span class="block truncate text-sm text-rt-text dark:text-white">{{ $item->package?->name ?? 'Programm entfernt' }}</span>
    <span class="mt-0.5 block truncate font-mono text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->package?->package_id }}</span>
</div>
<div data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}" class="min-w-0 px-2 py-2 {{ $hc(2) }}">
    <span class="{{ $chip }} {{ $stateClass }}">{{ $stateLabel }}</span>
    <span class="mt-1 block text-xs text-rt-muted dark:text-rt-dark-muted">
        @if($item->installed)
            {{ $item->reported_version ? 'Version '.$item->reported_version : 'Version nicht gemeldet' }}
        @elseif($stopped)
            {{ $item->attempts }} Versuche ohne Erfolg
        @elseif(! $open && $item->attempts > 0 && $item->next_attempt_at?->isFuture())
            Nächster Versuch ab {{ $item->next_attempt_at->format('d.m. H:i') }}
        @else
            Gemeldet {{ $item->reported_at?->format('d.m.Y H:i') ?? '–' }}
        @endif
    </span>
    @if($stopped && $item->can_assign)
        <button type="button" wire:click.stop="retry({{ $item->id }})" wire:loading.attr="disabled" wire:target="retry({{ $item->id }})"
            class="mt-1 inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-rt-border px-2.5 text-xs font-semibold text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted">
            <i class="far fa-redo" aria-hidden="true"></i>
            Erneut versuchen
        </button>
    @endif
</div>
<div data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}" class="min-w-0 px-2 py-2 text-sm {{ $hc(3) }}">
    @if($job)
        <span class="block text-rt-text dark:text-white">{{ $jobLabels[$job->status] ?? $job->status }}</span>
        <span class="mt-0.5 block text-xs text-rt-muted dark:text-rt-dark-muted">
            Versuch {{ $job->attempt }} · {{ $job->created_at?->format('d.m.Y H:i') }}@if($exitCode !== null) · Exit-Code {{ $exitCode }}@if($exitCode !== 0) ({{ sprintf('0x%08X', $exitCode & 0xFFFFFFFF) }})@endif @endif
        </span>
        @if(filled($result['message'] ?? null))
            <span class="mt-0.5 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $result['message'] }}</span>
        @endif
    @else
        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">Noch kein Auftrag</span>
    @endif
</div>
