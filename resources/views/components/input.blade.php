@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'rounded-lg border-rt-border bg-rt-surface text-rt-text shadow-sm focus:border-rt-accent focus:ring focus:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white']) !!}>
