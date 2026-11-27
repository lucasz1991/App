@props(['catalog', 'scope', 'model', 'value' => ''])
@php
    $fieldId = 'intake-'.$scope.'-'.str_replace('.', '-', $model);
    $raw = is_string($value) ? $value : '';
    $activity = $catalog->get(mb_strtolower(preg_replace('~\s*/\s*~u', '/', trim($raw))));
@endphp
@if($catalog->isEmpty())
    <x-operations.field :scope="$scope" label="Tätigkeit" :model="$model" maxlength="160" />
@else
    <div class="min-w-0 space-y-1.5">
        <x-ui.forms.label :for="$fieldId" value="Tätigkeit" />
        <x-ui.forms.input :id="$fieldId" :list="$fieldId.'-options'" :value="$activity?->displayLabel() ?? $raw"
            :aria-invalid="$errors->has($model) ? 'true' : null" :aria-describedby="$errors->has($model) ? \Illuminate\Support\Str::slug($model).'-error' : null"
            x-on:input="$wire.set('{{ $model }}', Array.from($el.list.options).find(option => option.value === $el.value)?.dataset.code ?? $el.value, false)" />
        <datalist id="{{ $fieldId }}-options">
            @foreach($catalog->unique('id') as $option)
                @if($option->active || $option->is($activity))
                    <option value="{{ $option->displayLabel() }}" data-code="{{ $option->code }}"></option>
                @endif
            @endforeach
        </datalist>
        <x-ui.forms.input-error :for="$model" />
    </div>
@endif
