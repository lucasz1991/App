@props(['mode' => 'basic', 'size' => 'md', 'can' => true])
@php

$modeClasses = match ($mode) {
    'primary', 'blue' => ' text-white bg-rt-red hover:bg-rt-red-dark focus:ring-rt-red/40 border-rt-red',
    'basic', 'secondary', 'light', 'white' => ' text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 focus:ring-rt-red/40 border-slate-300 dark:border-slate-600',
    'danger' => ' text-white bg-red-600 hover:bg-red-700 focus:ring-red-300 border-red-600',
    'success' => ' text-white bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-300 border-emerald-600',
    'warning' => ' text-white bg-amber-500 hover:bg-amber-600 focus:ring-amber-300 border-amber-500',
    'info' => ' text-white bg-sky-600 hover:bg-sky-700 focus:ring-sky-300 border-sky-600',
    'dark' => ' text-white bg-slate-800 hover:bg-slate-900 focus:ring-slate-700 border-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600 dark:border-slate-600',
    'link' => ' text-rt-red bg-transparent hover:bg-rt-red/10 focus:ring-rt-red/30 border-transparent',
};

$sizeClasses = match ($size) {
    'sm' => 'px-2 py-1 text-sm',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-5 py-3 text-lg',
    'xl' => 'px-6 py-4 text-xl',
    '2xl' => 'px-7 py-5 text-2xl',
};

if (is_string($can) && $can !== '') {
    $isAllowed = auth()->check() && auth()->user()->can($can);
} else {
    $isAllowed = (bool) $can;
}

$isDeniedByCan = ! $isAllowed;
$isDisabled = isset($attributes['disabled']) || $isDeniedByCan;

$classes = $modeClasses . ' ' . $sizeClasses;
$classes .= ' transition-all duration-100 inline-flex items-center justify-center gap-2 text-center font-semibold border rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50';

if ($isDisabled) {
    $classes .= ' opacity-80 cursor-not-allowed';
}

$title = $isDeniedByCan
    ? __('app.no_permission')
    : $attributes->get('title');

$attributesWithoutTitle = $attributes->except('title');

@endphp

@if (isset($attributes['href']))
    <a {!! $attributesWithoutTitle->merge(['class' => $classes]) !!}
        @if($title) title="{{ $title }}" @endif
        @if($isDisabled) aria-disabled="true" tabindex="-1" @endif
        x-data="{ isClicked: false }" 
        @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
        style="transform:scale(1);"
        :style="isClicked ? 'transform:scale(0.9);' : ''"
        >
        {{ $slot }}
    </a>
@else
    <button {!! $attributesWithoutTitle->merge(['class' => $classes]) !!}
        @if($title) title="{{ $title }}" @endif
        @if($isDisabled) disabled @endif
        x-data="{ isClicked: false }" 
        @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
        style="transform:scale(1);"
        :style="isClicked ? 'transform:scale(0.9);' : ''">
        {{ $slot }}
    </button>
@endif
