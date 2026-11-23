@props([
    'user',
    'size' => 10,
    // null: Rechte des angemeldeten Nutzers prüfen (Management-Dashboard + employees.view).
    'canView' => null,
    // Name im Linktext für Screenreader; Standard: Name des Nutzers.
    'label' => null,
])
@php
    $viewer = auth()->user();
    $linkable = $user && ($canView ?? ($viewer?->canViewManagementDashboard() && $viewer->can('employees.view')));
@endphp
@if($linkable)
    <a href="{{ route($viewer?->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $user->id) }}" wire:navigate aria-label="Profil öffnen: {{ $label ?? $user->name }}" {{ $attributes }}>
        <x-user.public-info :user="$user" :size="$size" :show-email="false" :show-presence="false" :local-initials="true" />
    </a>
@elseif($user)
    <x-user.public-info :user="$user" :size="$size" :show-email="false" :show-presence="false" :local-initials="true" />
@endif
