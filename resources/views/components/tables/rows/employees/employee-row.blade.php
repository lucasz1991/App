@php
    $hc = fn ($index) => $hideClass($columnsMeta[$index]['hideOn'] ?? 'none');

    /** @var \App\Models\User $item */
    $team = $item->currentTeam?->name ?? '—';
    $created = optional($item->created_at)->locale('de')->isoFormat('ll');
    $isSelected = in_array((int) $item->id, array_map('intval', $selectedItems ?? []), true);
    $viewer = auth()->user();
    $canCommunicate = $item->isActive() && ! $item->is($viewer);
    $canCall = $canCommunicate
        && ($viewer->isAdmin() || $viewer->hasRbacPermission('calls.start'))
        && ($item->isAdmin() || $item->hasRbacPermission('calls.join'));
@endphp

{{-- 0: Gemeinsame Personeninformation: Bild, Name und E-Mail --}}
<div
    data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}"
    class="rt-employee-name-cell min-w-0 px-2 py-2 pr-4 {{ $hc(0) }}"
>
    <x-user.person-anchor-preview
        :user="$item"
        :profile-url="$viewer->canViewManagementDashboard()
            ? route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $item->id)
            : null"
        :can-message="$viewer->can('users.messages.create')"
        :can-chat="$canCommunicate"
        :can-call="$canCall"
        chat-action="openDirectChat"
        call-action="startDirectCall"
        :selected="$isSelected"
    />
</div>

{{-- 1: Kontostatus – mobil neben der kombinierten Personeninformation sichtbar --}}
<div
    data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}"
    class="rt-employee-status-cell flex items-center px-2 py-2 {{ $hc(1) }}"
>
    <span
        @class([
            'rt-ui-badge inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold ring-1',
            'bg-emerald-50 text-emerald-700 ring-emerald-200/80 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $item->isActive(),
            'bg-slate-100 text-slate-600 ring-slate-200/80 dark:bg-slate-700/45 dark:text-slate-300 dark:ring-slate-600/50' => ! $item->isActive(),
        ])
    >
        <span @class([
            'h-1.5 w-1.5 rounded-full',
            'bg-emerald-500' => $item->isActive(),
            'bg-slate-400 dark:bg-slate-500' => ! $item->isActive(),
        ]) aria-hidden="true"></span>
        {{ $item->isActive() ? __('app.active') : __('app.inactive') }}
    </span>
</div>

{{-- 2: Team --}}
<div
    data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}"
    class="flex min-w-0 items-center px-2 py-2 {{ $hc(2) }}"
>
    <span class="truncate rounded-lg bg-rt-surface-muted px-2.5 py-1 text-xs font-medium text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
        {{ $team }}
    </span>
</div>

{{-- 3: Erstellt am --}}
<div
    data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}"
    class="px-2 py-2 text-sm tabular-nums text-rt-muted dark:text-rt-dark-muted {{ $hc(3) }}"
>
    {{ $created ?? '—' }}
</div>
