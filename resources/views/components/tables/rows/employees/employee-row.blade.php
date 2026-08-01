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
    $roleColor = $item->isAdmin() ? 'purple' : 'sky';

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

{{-- 1: Rolle, Kontostatus und Team in einer Spalte --}}
<div
    data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}"
    class="rt-employee-status-cell flex min-w-0 flex-wrap items-center gap-1.5 px-2 py-2 {{ $hc(1) }}"
>
    <x-ui.badge :color="$roleColor">
        <i class="far fa-user-tag" aria-hidden="true"></i>
        {{ $roleLabel }}
    </x-ui.badge>

    @unless ($item->isActive())
        <x-ui.badge color="red" :title="__('app.inactive')">
            <i class="far fa-ban" aria-hidden="true"></i>
            {{ ucfirst(__('app.inactive')) }}
        </x-ui.badge>
    @endunless

    {{-- Unterhalb der Tabellen-Bruchstelle bleibt nur Rolle und Sperrhinweis:
         zwei Badges nebeneinander wuerden dort umbrechen und die Zeilen
         unterschiedlich hoch machen. Das Team steht in der Schnellansicht. --}}
    @if ($showTeam)
        <x-ui.badge color="slate" class="hidden md:inline-flex">
            <i class="far fa-users" aria-hidden="true"></i>
            {{ $team }}
        </x-ui.badge>
    @endif
</div>

{{-- 2: Funktion --}}
<div
    data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}"
    class="flex min-w-0 items-center px-2 py-2 {{ $hc(2) }}"
>
    @if ($position)
        <span class="truncate text-sm text-rt-text dark:text-rt-dark-text">{{ $position }}</span>
    @else
        <span class="text-sm text-rt-soft dark:text-rt-dark-soft">—</span>
    @endif
</div>

{{-- 3: Zuletzt aktiv --}}
<div
    data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}"
    class="flex min-w-0 items-center gap-2 px-2 py-2 {{ $hc(3) }}"
>
    @if ($isOnline)
        <span class="relative flex h-2 w-2 shrink-0" aria-hidden="true">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
        </span>
        <span class="truncate text-sm font-semibold text-emerald-600 dark:text-emerald-300">{{ __('app.active_now') }}</span>
    @elseif ($lastActiveAt)
        <span class="h-2 w-2 shrink-0 rounded-full bg-slate-300 dark:bg-slate-600" aria-hidden="true"></span>
        <span class="truncate text-sm text-rt-muted dark:text-rt-dark-muted" title="{{ $lastActiveAt->format('d.m.Y H:i') }}">
            {{ $lastActiveAt->diffForHumans(short: true) }}
        </span>
    @else
        <span class="h-2 w-2 shrink-0 rounded-full bg-slate-300 dark:bg-slate-600" aria-hidden="true"></span>
        <span class="truncate text-sm text-rt-soft dark:text-rt-dark-soft">{{ __('app.never') }}</span>
    @endif
</div>
