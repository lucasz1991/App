@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-white', 'dropdownClasses' => ''])

@php
switch ($align) {
    case 'left':
        $alignmentClasses = 'ltr:origin-top-left rtl:origin-top-right start-0';
        break;
    case 'top':
        $alignmentClasses = 'origin-top';
        break;
    case 'none':
    case 'false':
        $alignmentClasses = '';
        break;
    case 'right':
    default:
        $alignmentClasses = 'ltr:origin-top-right rtl:origin-top-left end-0';
        break;
}

switch ($width) {
    case '48':
        $width = 'w-48';
        break;
}
@endphp

<div class="relative" x-data="{ open: false }" x-cloak @click.away="open = false" @close.stop="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-rt-spring duration-200"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            class="absolute z-50 mt-2 {{ $width }} rounded-xl shadow-rt-md {{ $alignmentClasses }} {{ $dropdownClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="rounded-xl overflow-hidden ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
