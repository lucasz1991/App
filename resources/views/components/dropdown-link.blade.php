@props([
    'can' => true,
    'tone' => 'default',
    'confirmMethod' => null,
    'confirmArguments' => [],
    'confirmTitle' => null,
    'confirmMessage' => null,
    'confirmVariant' => 'destructive',
    'confirmLabel' => null,
])

{{-- Rueckwaertskompatibler Alias auf x-ui.dropdown.dropdown-link.
     Wird nur noch von der Wagenliste (livewire/operations/wagon-list,
     dokumentierte Ausnahme des UI-Aufraeumplans) genutzt und rendert wie
     frueher immer ein Link-Element (as="a"). Neue Aufrufe nutzen x-ui.dropdown.dropdown-link
     direkt (UiStandardsGuardTest). Mehrwortige Props werden ausdruecklich
     weitergereicht, weil {{ $attributes }} sie sonst nicht zuordnet. --}}
<x-ui.dropdown.dropdown-link
    as="a"
    :can="$can"
    :tone="$tone"
    :confirm-method="$confirmMethod"
    :confirm-arguments="$confirmArguments"
    :confirm-title="$confirmTitle"
    :confirm-message="$confirmMessage"
    :confirm-variant="$confirmVariant"
    :confirm-label="$confirmLabel"
    {{ $attributes }}
>{{ $slot }}</x-ui.dropdown.dropdown-link>
