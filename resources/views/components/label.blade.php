@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-semibold leading-5 tracking-[-0.01em] text-rt-text dark:text-rt-dark-text']) }}>
    {{ $value ?? $slot }}
</label>
