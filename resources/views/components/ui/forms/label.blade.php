@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-slate-500 dark:text-slate-400 mb-2']) }}>
    {{ $value ?? $slot }}
</label>
