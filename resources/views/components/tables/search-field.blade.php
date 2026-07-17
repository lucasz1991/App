@props([
    'resultsCount' => 0,
])

@php
    $hasResults = $resultsCount > 0;
@endphp
<div 
    x-data="{ focused: false, value: @entangle($attributes->wire('model')) }" 
    x-cloak 
    class="relative"
    @click.away="focused = false"
>
    <div class="flex items-center rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-sm ring ring-offset-4 ring-offset-rt-canvas transition-all duration-300 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:ring-offset-rt-dark-canvas"
        :class="{
            'w-[300px]': focused || value.length > 0,
            'w-[30px]': !(focused || value.length > 0),
            'ring-rt-red/30': value.length === 0 && focused,
            'ring-transparent': value.length === 0 && !focused,
            'ring ring-emerald-200 dark:ring-emerald-500/30': value.length > 0 && {{ $hasResults ? 'true' : 'false' }},
            'ring ring-red-200 dark:ring-red-500/30': value.length > 0 && {{ $hasResults ? 'false' : 'true' }}
        }"
    >
        <input 
            x-ref="searchInput"
            @focus="focused = true"
            type="text" 
            placeholder="Search..." 
            x-model="value"
            {{ $attributes->merge(['class' => 'w-full border-none bg-transparent px-2 py-1 text-base text-rt-text placeholder:text-rt-soft focus:border-transparent focus:ring-0 dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft']) }}
            :class="(focused || value.length > 0) ? 'w-full' : 'hidden'"
        />
        <!-- Clear Button -->
        <div x-show="value.length > 0" x-cloak>
            <button 
                type="button"
                @click="value = ''; $refs.searchInput.focus(); focused = false"
                class="flex h-[30px] w-[30px] cursor-pointer items-center justify-center text-rt-soft hover:text-rt-accent dark:text-rt-dark-soft dark:hover:text-rt-dark-accent"
            >
                ✕
            </button>
        </div>
        <!-- Icon -->
        <div @click="focused = true; $refs.searchInput.focus()"
            class="flex h-[30px] w-[30px] cursor-pointer items-center justify-center text-rt-soft hover:text-rt-accent dark:text-rt-dark-soft dark:hover:text-rt-dark-accent">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192.904 192.904" class="h-4 w-4">
                <path d="m190.707 180.101-47.078-47.077c11.702-14.072 18.752-32.142 18.752-51.831C162.381 36.423 125.959 0 81.191 0 36.422 0 0 36.423 0 81.193c0 44.767 36.422 81.187 81.191 81.187 19.688 0 37.759-7.049 51.831-18.751l47.079 47.078a7.474 7.474 0 0 0 5.303 2.197 7.498 7.498 0 0 0 5.303-12.803zM15 81.193C15 44.694 44.693 15 81.191 15c36.497 0 66.189 29.694 66.189 66.193 0 36.496-29.692 66.187-66.189 66.187C44.693 147.38 15 117.689 15 81.193z"></path>
            </svg>
        </div>
    </div>
</div>
