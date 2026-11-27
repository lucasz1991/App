@props(['model', 'coordinateFields' => false, 'coordinatesOnly' => false, 'locality' => false])
@php
    $leaf = \Illuminate\Support\Str::afterLast($model, '.');
    $prefix = str_contains($model, '.') ? \Illuminate\Support\Str::beforeLast($model, '.').'.' : '';
    $address = in_array($leaf, ['street', 'address'], true);
    $fields = $address ? ['street' => $model, 'postal' => $prefix.($leaf === 'address' ? 'postalCode' : 'postal_code'),
        'city' => $prefix.'city', 'country' => $prefix.'country'] : [];
    if ($coordinateFields || $coordinatesOnly) {
        $fields += ['latitude' => 'form.latitude', 'longitude' => 'form.longitude'];
        if ($coordinateFields) $fields['confirmed'] = 'form.coordinates_confirmed';
    }
    $config = ['model' => $model, 'fields' => $fields, 'place' => !$address, 'locality' => $locality,
        'coordinatesOnly' => $coordinatesOnly, 'inline' => $prefix === 'inlineValues.',
        'search' => route('places.search', [], false), 'portalSearch' => route('customer-portal.places.search', [], false),
        'reverse' => route('places.reverse', [], false)];
@endphp
<div class="relative min-w-0" x-data="rtPlaceField(@js($config))" x-id="['places-list']" x-on:click.outside="close()"
    x-on:focusout="if (!$el.contains($event.relatedTarget)) close()" x-on:input="input($event)">
    <input {{ $attributes->except(['x-ref']) }} x-ref="input" role="combobox" aria-autocomplete="list" x-bind:aria-expanded="Boolean(rows.length)"
        x-bind:aria-controls="$id('places-list')" x-bind:aria-activedescendant="active >= 0 ? $id('places-list')+'-'+active : null"
        x-on:keydown.arrow-down="if (rows.length) { $event.preventDefault(); move(1) }"
        x-on:keydown.arrow-up="if (rows.length) { $event.preventDefault(); move(-1) }"
        x-on:keydown.enter.capture="if (active >= 0) { $event.preventDefault(); $event.stopImmediatePropagation(); select(rows[active]) }"
        x-on:keydown.escape="if (rows.length) { $event.stopPropagation(); close() }">
    <ul x-cloak x-show="rows.length" x-bind:id="$id('places-list')" role="listbox" aria-label="TomTom-Ortsvorschläge"
        class="relative z-10 mt-1 max-h-56 overflow-y-auto rounded-lg border border-rt-border bg-rt-surface p-1 shadow-lg dark:bg-rt-dark-surface">
        <template x-for="(row, index) in rows" :key="index">
            <li role="option" x-bind:id="$id('places-list')+'-'+index" x-bind:aria-selected="active === index"
                x-on:pointerdown.prevent x-on:click="select(row)" x-on:mouseenter="active = index"
                class="cursor-pointer rounded p-2 text-sm" x-bind:class="active === index && 'bg-rt-accent-soft'">
                <span x-text="text(row)"></span><small class="block text-rt-muted" x-text="detail(row)"></small>
            </li>
        </template>
    </ul>
    <small role="status" x-cloak x-show="message" x-text="message" class="block text-rt-muted"></small>
    @if($address || $coordinatesOnly)
        <button type="button" x-show="!portal" class="text-xs text-rt-muted" x-on:mousedown.prevent x-on:click="gps()">Meinen Standort verwenden</button>
    @endif
</div>
