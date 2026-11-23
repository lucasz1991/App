@props([
    'id',
    'maxWidth',
    'placement' => 'center',
    'showExpression' => null,
    // Ebene (z-index) der Huelle. 190 ist die gemeinsame Modal-Ebene; nur
    // Dialoge, die ueber einem anderen Overlay liegen muessen (etwa die
    // Live-Standortkarte ueber dem Chat), setzen einen hoeheren Wert.
    'layer' => 190,
    // Optionaler Alpine-Ausdruck, der statt "show = false" ausgefuehrt wird,
    // wenn der Dialog per Schliessen-Knopf, Escape oder Hintergrund-Klick
    // geschlossen werden soll. Er muss den Zustand selbst zuruecksetzen und
    // darf das Schliessen verweigern (z. B. waehrend einer laufenden Aktion).
    'closeAction' => null,
    // Zusatzklassen fuer das Panel, z. B. eine feste Hoehe.
    'panelClass' => null,
    // Nur mit showExpression: true = die Huelle legt KEIN eigenes x-data an
    // und arbeitet direkt mit dem Zustand des Aufrufers. Noetig, wenn der
    // Aufrufer per this.$refs auf x-ref im Dialoginhalt zugreift: Alpine sucht
    // Refs nur aufwaerts, eine Zwischenwurzel wuerde sie verdecken. Dafuer
    // fehlt im Inhalt die lokale Variable "show" (z. B. fuer resetOnOpen-Tabs).
    'inheritScope' => false,
])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
    '5xl' => 'sm:max-w-5xl',
    '6xl' => 'sm:max-w-6xl',
][$maxWidth ?? '2xl'] ?? 'sm:max-w-2xl';

$dialogRole = $attributes->get('role', 'dialog');
$labelledBy = $attributes->get('aria-labelledby');
$describedBy = $attributes->get('aria-describedby');
$ariaLabel = $attributes->get('aria-label', config('app.name') . ' Dialog');
$isDrawer = $placement === 'right';
$resolvedLayer = max(1, (int) $layer);
$inheritsScope = filled($showExpression) && filter_var($inheritScope, FILTER_VALIDATE_BOOLEAN);
$showState = $inheritsScope ? $showExpression : 'show';
$closeExpression = filled($closeAction) ? $closeAction : $showState.' = false';
// data-*-Marker der Aufrufer (z. B. data-page-builder-subdialog) gehoeren an
// das sichtbare Panel, damit Skripte den offenen Dialog per closest()/
// querySelector() finden und an seiner Sichtbarkeit erkennen.
$panelDataAttributes = $attributes->filter(fn ($value, $key) => str_starts_with($key, 'data-'));
@endphp

{{-- Die Huelle selbst bleibt unsichtbar (hidden): Der Dialog wird nach body
     teleportiert. So erzeugt sie in Flex-/Grid-/space-y-Layouts der Aufrufer
     keine leere Zelle und keinen zusaetzlichen Abstand. --}}
<div
    hidden
    @if ($inheritsScope)
        {{-- kein eigenes x-data: Sichtbarkeit = Zustand des Aufrufers --}}
    @elseif ($showExpression)
        x-data="{ show: false }"
        x-init="show = !!({{ $showExpression }}); $watch('{{ $showExpression }}', value => show = !!value); $watch('show', value => {{ $showExpression }} = value)"
    @else
        x-data="{ show: @entangle($attributes->wire('model')) }"
    @endif
    x-on:close.stop="{{ $closeExpression }}"
    x-on:rt-navigation:prepare.window="{{ $showState }} = false"
    x-on:keydown.escape.window="if ({{ $showState }}) { {{ $closeExpression }} }"
>
    <template x-teleport="body">
        <div
            x-show.important="{{ $showState }}"
            x-on:close.stop="{{ $closeExpression }}"
            x-trap.inert.noscroll="{{ $showState }}"
            id="{{ $id }}"
            class="rt-ui-modal rt-modal-shell {{ $isDrawer ? 'rt-disposition-drawer' : 'rt-modal-center-shell' }} jetstream-modal fixed inset-0 z-[190] overflow-x-hidden overflow-y-auto overscroll-contain p-3 sm:p-6"
            @if ($resolvedLayer !== 190)
                style="display: none; z-index: {{ $resolvedLayer }} !important;"
            @else
                style="display: none;"
            @endif
            data-rt-modal-shell
            data-rt-overlay-layer
            data-rt-overlay-base="{{ $resolvedLayer }}"
        >
            <div x-show.important="{{ $showState }}" aria-hidden="true" class="rt-modal-backdrop fixed inset-0" x-on:click="{{ $closeExpression }}" x-transition:enter="rt-motion-backdrop-enter"
                            x-transition:enter-start="rt-motion-faded"
                            x-transition:enter-end="rt-motion-shown"
                            x-transition:leave="rt-motion-backdrop-leave"
                            x-transition:leave-start="rt-motion-shown"
                            x-transition:leave-end="rt-motion-faded">
                <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-md"></div>
            </div>

            <div x-show.important="{{ $showState }}"
                            role="{{ $dialogRole }}"
                            aria-modal="true"
                            @if (filled($labelledBy))
                                aria-labelledby="{{ $labelledBy }}"
                            @else
                                aria-label="{{ $ariaLabel }}"
                            @endif
                            @if (filled($describedBy))
                                aria-describedby="{{ $describedBy }}"
                            @endif
                            class="rt-ui-surface rt-ui-modal-panel rt-modal-frame {{ $isDrawer ? 'rt-disposition-drawer__panel' : '' }} relative my-auto flex max-h-[calc(100dvh-1.5rem)] min-h-0 min-w-0 w-full max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 sm:max-h-[calc(100dvh-3rem)] {{ $maxWidth }} {{ $panelClass }}"
                            x-transition:enter="rt-motion-modal-enter"
                            x-transition:enter-start="rt-motion-modal-enter-from"
                            x-transition:enter-end="rt-motion-modal-enter-to"
                            x-transition:leave="rt-motion-modal-leave"
                            x-transition:leave-start="rt-motion-modal-leave-from"
                            x-transition:leave-end="rt-motion-modal-leave-to"
                            {{ $panelDataAttributes }}
                            data-rt-modal-panel>
                {{ $slot }}
            </div>

            {{--
                Teleport-Ziel fuer Selects und verschachtelte Anchor-Dropdowns.
                Es liegt im selben Focus-Trap wie der Dialog, aber ausserhalb
                des animierten Panels, damit fixed-Geometrie viewportbezogen
                bleibt und Listen den Modal-Inhalt nicht aufweiten.
            --}}
            <div
                wire:ignore
                class="pointer-events-none fixed inset-0 z-[20]"
                data-rt-overlay-portal
                data-rt-modal-portal
            ></div>
        </div>
    </template>
</div>
