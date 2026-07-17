@props(['padding' => 'p-6'])

<section {{ $attributes->class("rounded-xl border border-rt-border bg-rt-surface text-rt-text shadow-sm dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text {$padding}") }}>
    {{ $slot }}
</section>
