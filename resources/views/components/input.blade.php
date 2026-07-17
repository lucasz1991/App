@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'rounded-lg border-slate-300 shadow-sm focus:border-rt-red focus:ring focus:ring-rt-red/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200']) !!}>
