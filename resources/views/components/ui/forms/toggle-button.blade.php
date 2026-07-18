@props([
    'id'    => 'toggle-' . Str::random(6),
    'label' => null,
    'model' => null,   // z. B. maintenanceMode
    'change' => null,  // optional: JS change handler
])

<label for="{{ $id }}" class="flex items-center cursor-pointer select-none">
    <input 
        id="{{ $id }}"
        type="checkbox"
        @if($model) wire:model.live="{{ $model }}" @endif
        @if($change) @change="{{ $change }}" @endif
        class="sr-only peer"
    />

    {{-- Slider --}}
    <div class="relative w-9 h-5 bg-rt-surface-muted peer-focus:outline-none peer-focus:ring-4
                peer-focus:ring-rt-red/30 dark:peer-focus:ring-rt-red/40 rounded-full peer
                dark:bg-rt-dark-surface-muted
                transition-all duration-300 ease-rt-spring
                peer-checked:after:translate-x-full
                rtl:peer-checked:after:-translate-x-full
                peer-checked:after:border-white
                after:content-[''] after:absolute after:top-[2px] after:start-[2px]
                after:bg-white after:border-rt-border after:border after:rounded-full
                after:h-4 after:w-4 after:transition-all after:ease-rt-spring dark:border-rt-dark-border
                peer-checked:bg-rt-red">
    </div>

    @if($label)
        <span class="ms-3 text-sm font-medium text-slate-900 dark:text-slate-300">
            {{ $label }}
        </span>
    @endif
</label>
