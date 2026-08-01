@props([
    'user',
    'profileUrl' => null,
    'canMessage' => false,
    'canChat' => false,
    'canCall' => false,
    'chatAction' => null,
    'callAction' => null,
    'messageAction' => 'openMessage',
    'selected' => false,
    'triggerClasses' => 'inline-flex',
])

@php
    $position = $user->profile?->position;
    $team = $user->currentTeam?->name;

    // Kopfzeile fasst Funktion und Team zusammen; leere Werte entfallen,
    // statt eine Zeile mit Gedankenstrich zu hinterlassen. Telefonnummern
    // bleiben bewusst aussen vor — die Schnellansicht steht allen Kolleginnen
    // und Kollegen offen, Kontaktdaten gehoeren ins Profil.
    $subline = implode(' · ', array_filter([$position, $team]));
@endphp

<x-ui.dropdown.anchor-dropdown
    align="left"
    width="72"
    :offset="10"
    content-role="dialog"
    content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    :trigger-classes="$triggerClasses"
    data-person-anchor-preview
>
    <x-slot:trigger>
        @isset($trigger)
            {{ $trigger }}
        @else
            <button
                type="button"
                class="group min-w-0 rounded-lg px-1 py-0.5 text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent"
                aria-label="{{ __('app.open_person_preview') }}: {{ $user->name }}"
                title="{{ __('app.open_person_preview') }}"
                data-table-row-ignore
            >
                <x-user.public-info
                    :user="$user"
                    :size="10"
                    :show-email="true"
                    :selected="$selected"
                />
            </button>
        @endisset
    </x-slot:trigger>

    <x-slot:content>
        <article class="w-72 max-w-[calc(100vw-1.5rem)] overflow-hidden" aria-label="{{ __('app.person_preview') }}: {{ $user->name }}">
            {{-- Kopf: Person auf einen Blick --}}
            <div class="flex min-w-0 items-center gap-3 px-4 pb-3 pt-4">
                <img
                    src="{{ $user->profile_photo_url }}"
                    alt=""
                    class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                >
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-5 text-rt-text dark:text-rt-dark-text">{{ $user->name }}</p>
                    @if ($subline !== '')
                        <p class="truncate text-xs leading-4 text-rt-muted dark:text-rt-dark-muted">{{ $subline }}</p>
                    @endif
                </div>
            </div>

            {{-- Daten: nur Werte, ohne Beschriftungsspalte --}}
            <div class="flex min-w-0 items-center gap-2 px-4 pb-3 text-xs text-rt-muted dark:text-rt-dark-muted">
                <i class="far fa-envelope w-3.5 shrink-0 text-center text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                <span class="truncate">{{ $user->email }}</span>
            </div>

            {{-- Aktionen: kompakte Symbolleiste am Fuss der Karte --}}
            <x-user.quick-actions
                :user="$user"
                :profile-url="$profileUrl"
                :can-chat="$canChat"
                :can-call="$canCall"
                :can-message="$canMessage"
                :chat-action="$chatAction"
                :call-action="$callAction"
                :message-action="$messageAction"
                variant="row"
                class="border-t border-rt-border/70 px-2.5 py-1.5 dark:border-rt-dark-border/70"
            />
        </article>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
