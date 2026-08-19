@php
    $hc = fn ($index) => $hideClass($columnsMeta[$index]['hideOn'] ?? 'none');

    /** @var \App\Models\Device $item */
    $assignment = $item->activeAssignment;
    $compliance = $item->compliance_status->value;
    $management = $item->management_status->value;
    $platform = $item->platform->value;

    $platformLabel = match ($platform) {
        'macos' => 'macOS',
        'ios' => 'iOS',
        'ipados' => 'iPadOS',
        default => ucfirst($platform),
    };
    $platformIcon = match ($platform) {
        'android' => 'fa-android',
        'ios', 'ipados', 'macos' => 'fa-apple',
        'linux' => 'fa-linux',
        default => 'fa-windows',
    };
    $complianceLabel = match ($compliance) {
        'compliant' => 'Konform',
        'warning' => 'Warnung',
        'non_compliant' => 'Nicht konform',
        'exempt' => 'Ausgenommen',
        default => 'Unbekannt',
    };
    $complianceIcon = match ($compliance) {
        'compliant' => 'fa-check-circle',
        'warning' => 'fa-exclamation-triangle',
        'non_compliant' => 'fa-times-circle',
        'exempt' => 'fa-shield-alt',
        default => 'fa-question-circle',
    };
    $managementLabel = match ($management) {
        'managed' => 'Verwaltet',
        'limited' => 'Eingeschränkt',
        'pending' => 'Einladung offen',
        'error' => 'Fehler',
        default => 'Nicht verwaltet',
    };
@endphp

<div
    data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}"
    data-device-row
    class="rt-device-name-cell min-w-0 px-2 py-2 {{ $hc(0) }}"
>
    <button
        type="button"
        wire:click.stop="selectDeviceById({{ $item->id }})"
        class="block w-full min-w-0 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35"
        title="Details zu {{ $item->display_name ?: $item->hostname ?: 'Unbenanntes Gerät' }} öffnen"
    >
        <span class="block truncate text-sm font-semibold text-rt-text transition hover:text-rt-red dark:text-white dark:hover:text-rt-red-light">
            {{ $item->display_name ?: $item->hostname ?: 'Unbenanntes Gerät' }}
        </span>
        <span class="mt-0.5 block truncate text-xs font-normal text-rt-muted dark:text-rt-dark-muted">
            {{ $item->asset_tag ?: $item->serial_number ?: 'Ohne Inventarkennung' }}
        </span>
    </button>
</div>

<div
    data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}"
    class="min-w-0 px-2 py-2 {{ $hc(1) }}"
>
    <span class="block truncate text-sm font-medium text-rt-text dark:text-white">
        {{ $assignment?->user?->name ?? 'Im Lager' }}
    </span>
    @if($assignment?->user?->email)
        <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $assignment->user->email }}</span>
    @else
        <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">Nicht zugewiesen</span>
    @endif
</div>

<div
    data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}"
    class="min-w-0 px-2 py-2 {{ $hc(2) }}"
>
    <span class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-rt-border px-2 py-1 text-xs font-medium text-rt-text dark:border-rt-dark-border dark:text-white">
        <i class="fab {{ $platformIcon }} shrink-0" aria-hidden="true"></i>
        <span class="truncate">{{ $platformLabel }}</span>
    </span>
</div>

<div
    data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}"
    class="min-w-0 px-2 py-2 {{ $hc(3) }}"
>
    <span class="block truncate text-sm text-rt-text dark:text-white">{{ $item->declared_location ?: 'Nicht gemeldet' }}</span>
    <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
        {{ $item->form_factor ? ucfirst(str_replace('_', ' ', (string) $item->form_factor)) : 'Gerät' }}
    </span>
</div>

<div
    data-rt-table-label="{{ $columnsMeta[4]['label'] ?? '' }}"
    class="rt-device-status-cell flex min-w-0 flex-wrap items-center gap-1.5 px-2 py-2 {{ $hc(4) }}"
>
    <span @class([
        'rt-device-status-badge inline-flex min-h-8 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $compliance === 'compliant',
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => $compliance === 'warning',
        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' => $compliance === 'non_compliant',
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' => ! in_array($compliance, ['compliant', 'warning', 'non_compliant'], true),
    ]) title="{{ $complianceLabel }}">
        <i class="far {{ $complianceIcon }}" aria-hidden="true"></i>
        <span class="rt-device-status-label">{{ $complianceLabel }}</span>
    </span>

    <span @class([
        'hidden rounded-md px-2 py-1 text-[11px] font-semibold lg:inline-flex',
        'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' => $management === 'managed',
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => in_array($management, ['limited', 'pending'], true),
        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' => $management === 'error',
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' => ! in_array($management, ['managed', 'limited', 'pending', 'error'], true),
    ])>{{ $managementLabel }}</span>

    <span class="hidden w-full truncate text-[11px] text-rt-muted xl:block dark:text-rt-dark-muted">
        Sync: {{ $item->last_synced_at?->diffForHumans() ?? 'noch nie' }}
    </span>
</div>
