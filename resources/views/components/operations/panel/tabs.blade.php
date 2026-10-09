@props([
    'tabs' => [],
    'label',
    'idPrefix',
    'model' => 'tab',
    // Server-owned lazy panels use manual activation: focus alone never loads data.
    'action' => null,
    'active' => null,
])
{{--
    Reiterleiste nach dem APG-Muster: Pfeiltasten, Pos1 und Ende bewegen den Fokus,
    Standard: Fokus aktiviert den Reiter, dessen Inhalt bereits vorliegt.
    Mit action: Klick/Enter lädt den Bereich serverseitig; Fokus bleibt beim Reiter.
    Jeder Eintrag: ['label' => …, 'count' => optional, 'countExpression' => optional (Alpine,
    zählt live mit, z. B. für Auswahlfelder), 'alert' => optional bool].
--}}
<div {{ $attributes->class('rt-ops-panel__tabs') }} role="tablist" aria-label="{{ $label }}"
    x-on:keydown.arrow-right.prevent="$focus.within($el).wrap().next()"
    x-on:keydown.arrow-left.prevent="$focus.within($el).wrap().previous()"
    x-on:keydown.home.prevent="$focus.within($el).first()"
    x-on:keydown.end.prevent="$focus.within($el).last()">
    @foreach($tabs as $key => $tab)
        <button type="button" role="tab" class="rt-ops-panel__tab"
            id="{{ $idPrefix }}-tab-{{ $key }}" aria-controls="{{ $idPrefix }}-panel-{{ $key }}"
            aria-selected="{{ ($action ? (string) $active === (string) $key : $loop->first) ? 'true' : 'false' }}" tabindex="{{ ($action ? (string) $active === (string) $key : $loop->first) ? '0' : '-1' }}"
            @if($action)
                x-on:click="await $wire.call(@js($action), @js((string) $key)); if ($el.isConnected) $el.focus({ preventScroll: true })"
                wire:loading.attr="disabled" wire:target="{{ $action }}"
            @else
                x-bind:aria-selected="{{ $model }} === @js((string) $key) ? 'true' : 'false'"
                x-bind:tabindex="{{ $model }} === @js((string) $key) ? 0 : -1"
                x-on:click="{{ $model }} = @js((string) $key)"
                x-on:focus="{{ $model }} = @js((string) $key)"
            @endif
            data-panel-tab="{{ $key }}">
            @if(filled($tab['icon'] ?? null))
                <i class="{{ $tab['icon'] }}" aria-hidden="true"></i>
            @endif
            <span>{{ $tab['label'] }}</span>
            @if(filled($tab['countExpression'] ?? null))
                <span class="rt-ops-panel__count" x-text="{{ $tab['countExpression'] }}">{{ $tab['count'] ?? '' }}</span>
            @elseif(isset($tab['count']) && $tab['count'] !== '' && $tab['count'] !== null)
                <span class="rt-ops-panel__count">{{ $tab['count'] }}</span>
            @endif
            @if(! empty($tab['alert']))
                <span class="rt-ops-panel__alert" aria-hidden="true"></span><span class="sr-only">Enthält Hinweise</span>
            @endif
        </button>
    @endforeach
</div>
