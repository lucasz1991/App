@props(['label'])

<div class="flex justify-between border-b border-dotted border-rt-border py-1 text-sm text-rt-text dark:border-rt-dark-border dark:text-rt-dark-text">
    <div class="font-medium text-rt-muted dark:text-rt-dark-muted">{{ $label }}:</div>
    <div class="text-right">{{ $slot }}</div>
</div>
