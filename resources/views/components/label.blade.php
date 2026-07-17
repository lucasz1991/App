@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-rt-text dark:text-rt-dark-text']) }}>
    {{ $value ?? $slot }}
</label>
