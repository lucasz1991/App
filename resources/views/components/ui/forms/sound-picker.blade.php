@props([
    // Livewire-Eigenschaft (Array Ereignis => Signatur), z. B. "sounds".
    'model' => 'sounds',
    // Mit "Systemstandard"-Option (Profil). Leerer Wert = Standard verwenden.
    'allowDefault' => false,
    // Systemweite Zuordnung — zeigt bei "Systemstandard" den wirksamen Ton.
    'systemMap' => [],
    // Optionale Livewire-Methode zum Speichern direkt nach der Auswahl.
    'autosave' => null,
])

@php
    $events = \App\Support\Sound\SoundLibrary::events();
    $signatures = \App\Support\Sound\SoundLibrary::signatures();

    $eventIcons = [
        'success' => 'far fa-check-circle',
        'error' => 'far fa-times-circle',
        'warning' => 'far fa-exclamation-triangle',
        'info' => 'far fa-info-circle',
        'message' => 'far fa-envelope',
        'chat' => 'far fa-comments',
    ];

    $eventTones = [
        'success' => 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-300',
        'error' => 'bg-rt-red/10 text-rt-red dark:text-rt-dark-accent',
        'warning' => 'bg-amber-500/12 text-amber-600 dark:text-amber-300',
        'info' => 'bg-sky-500/12 text-sky-600 dark:text-sky-300',
        'message' => 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent',
        'chat' => 'bg-violet-500/12 text-violet-600 dark:text-violet-300',
    ];
@endphp

<div {{ $attributes->class('grid gap-3 lg:grid-cols-2') }} data-sound-picker>
    @foreach ($events as $event)
        @php
            $fallback = $systemMap[$event] ?? \App\Support\Sound\SoundLibrary::defaults()[$event];
            $changeHandler = "window.RTSound?.preview(selected || '{$fallback}')";
            if (filled($autosave) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $autosave)) {
                $changeHandler .= '; $nextTick(() => $wire.'.(string) $autosave.'())';
            }
        @endphp
        <div
            class="group flex items-center gap-3 rounded-2xl bg-rt-surface p-3 shadow-rt-xs ring-1 ring-rt-border/60 transition-shadow hover:shadow-rt-sm dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
            data-sound-picker-row="{{ $event }}"
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $eventTones[$event] ?? 'bg-rt-surface-muted text-rt-muted' }}">
                <i class="{{ $eventIcons[$event] ?? 'far fa-volume' }} text-base" aria-hidden="true"></i>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    {{ \App\Support\Sound\SoundLibrary::eventLabel($event) }}
                </p>

                <div class="mt-1.5 flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        {{-- Auswahl mit Sofort-Vorschau: beim Wechsel erklingt die
                             gewaehlte Signatur direkt (bei Systemstandard der
                             wirksame Standardton). --}}
                        <x-ui.forms.select
                            wire:model="{{ $model }}.{{ $event }}"
                            :change="$changeHandler"
                        >
                            @if ($allowDefault)
                                <option value="">
                                    {{ __('app.sound_use_default') }} ({{ \App\Support\Sound\SoundLibrary::signatureLabel($fallback) }})
                                </option>
                            @endif
                            @foreach ($signatures as $signature)
                                <option value="{{ $signature }}">
                                    {{ \App\Support\Sound\SoundLibrary::signatureLabel($signature) }}
                                </option>
                            @endforeach
                        </x-ui.forms.select>
                    </div>

                    {{-- Aktuellen Ton anhoeren (unabhaengig vom An/Aus-Schalter,
                         weil es eine direkte Nutzeraktion ist). --}}
                    <button
                        type="button"
                        x-data
                        x-on:click="window.RTSound?.preview(($wire.{{ $model }} || {})['{{ $event }}'] || '{{ $fallback }}')"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs transition hover:border-rt-accent/50 hover:text-rt-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30 active:scale-95 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent"
                        aria-label="{{ __('app.sound_preview') }}"
                        title="{{ __('app.sound_preview') }}"
                        data-sound-preview="{{ $event }}"
                    >
                        <i class="far fa-play text-sm" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    @endforeach
</div>
