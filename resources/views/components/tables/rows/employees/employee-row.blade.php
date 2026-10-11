@php
    $hc = fn ($index) => $hideClass($columnsMeta[$index]['hideOn'] ?? 'none');

    /** @var \App\Models\User $item */
    $team = $item->currentTeam?->name;
    $isSelected = in_array((int) $item->id, array_map('intval', $selectedItems ?? []), true);
    $viewer = auth()->user();
    $canCommunicate = $item->isActive() && ! $item->is($viewer);
    $canCall = $canCommunicate
        && ($viewer->isAdmin() || $viewer->hasRbacPermission('calls.start'))
        && ($item->isAdmin() || $item->hasRbacPermission('calls.join'));

    $roleKey = 'app.role_' . $item->role;
    $roleLabel = __($roleKey);
    $roleLabel = $roleLabel === $roleKey ? ucfirst((string) $item->role) : $roleLabel;

    // Team und Rolle heissen haeufig gleich ("Mitarbeiter") — dann genuegt
    // eine Angabe.
    $showTeam = $team && mb_strtolower($team) !== mb_strtolower($roleLabel);

    // Stammt aus dem withMax-Aggregat der Liste, kostet also keine
    // zusaetzliche Abfrage je Zeile.
    $lastActiveAt = $item->lastActivityAt();
    $isOnline = $item->isOnline();
    $position = $item->profile?->position;
@endphp

{{-- 0: Gemeinsame Personeninformation: Bild, Name und E-Mail --}}
<div
    role="cell"
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
        :selection-indicator="true"
    />
</div>

{{-- 1: Function, role and team are organizational facts, not account status. --}}
<div role="cell" data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}"
    class="rt-employee-position-cell min-w-0 px-2 py-2 {{ $hc(1) }}">
    <span class="block truncate text-sm font-medium" title="{{ $position }}">{{ $position ?: '—' }}</span>
    <span class="employee-directory__secondary block truncate" title="{{ $roleLabel }}{{ $showTeam ? ' · '.$team : '' }}">
        {{ $roleLabel }}@if($showTeam) · {{ $team }}@endif
    </span>
</div>

{{-- 2: Explicit account state, separate from the role. --}}
<div role="cell" data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}"
    class="rt-employee-status-cell min-w-0 px-2 py-2 {{ $hc(2) }}">
    <span class="employee-directory__account-state" data-active="{{ $item->isActive() ? 'true' : 'false' }}">
        <span aria-hidden="true"></span>{{ ucfirst(__($item->isActive() ? 'app.active' : 'app.inactive')) }}
    </span>
    <span class="employee-directory__secondary block truncate md:hidden">{{ $position ?: $roleLabel }}</span>
</div>

{{-- 3: Last activity remains a useful, sortable operational signal. --}}
<div role="cell" data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}"
    class="flex min-w-0 items-center gap-2 px-2 py-2 {{ $hc(3) }}">
    @if ($isOnline)
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
        <span class="truncate text-sm text-emerald-600 dark:text-emerald-300">{{ __('app.active_now') }}</span>
    @elseif ($lastActiveAt)
        <span class="truncate text-sm text-rt-muted dark:text-rt-dark-muted" title="{{ $lastActiveAt->format('d.m.Y H:i') }}">
            {{ $lastActiveAt->diffForHumans(short: true) }}
        </span>
    @else
        <span class="truncate text-sm text-rt-soft dark:text-rt-dark-soft">{{ __('app.never') }}</span>
    @endif
</div>
