@props(['value'])

<label {{ $attributes->merge(['class' => 'mb-2 block text-sm font-medium text-rt-text dark:text-rt-dark-text']) }}>
    {{ $value ?? $slot }}
</label>
