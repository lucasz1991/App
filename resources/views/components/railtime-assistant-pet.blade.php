<svg
    {{ $attributes->merge(['class' => 'rt-assistant-pet']) }}
    viewBox="0 0 120 120"
    fill="none"
    aria-hidden="true"
    focusable="false"
>
    <g class="rt-assistant-pet__signal" stroke="currentColor" stroke-linecap="round">
        <path d="M99 42c4 3 4 8 0 11" stroke-width="3" />
        <path d="M106 36c8 7 8 17 0 24" stroke-width="3" />
    </g>

    <g class="rt-assistant-pet__tail">
        <path
            d="M78 78c23-19 42-7 36 11-4 12-17 19-31 12 12-4 18-12 13-18-4-5-11-3-18 3V78Z"
            class="rt-assistant-pet__fur"
        />
        <path
            d="M109 76c7 5 7 14 2 20-3 4-8 7-13 8 5-4 8-8 7-13-1-6-5-9-10-10 5-4 10-6 14-5Z"
            class="rt-assistant-pet__cream"
        />
    </g>

    <path
        d="M34 70c0-15 12-25 26-25s26 10 26 25v21c0 11-9 19-20 19H54c-11 0-20-8-20-19V70Z"
        class="rt-assistant-pet__fur"
    />
    <path d="M43 94c4-5 10-7 17-7s13 2 17 7v8H43v-8Z" class="rt-assistant-pet__cream" />

    <g class="rt-assistant-pet__ear rt-assistant-pet__ear--left">
        <path d="M31 43 34 18l19 17-22 8Z" class="rt-assistant-pet__fur" />
        <path d="m36 35 2-10 8 8-10 2Z" class="rt-assistant-pet__ear-inner" />
    </g>
    <g class="rt-assistant-pet__ear rt-assistant-pet__ear--right">
        <path d="m89 43-3-25-19 17 22 8Z" class="rt-assistant-pet__fur" />
        <path d="m84 35-2-10-8 8 10 2Z" class="rt-assistant-pet__ear-inner" />
    </g>

    <path
        d="M25 56c0-18 15-32 35-32s35 14 35 32c0 20-14 35-35 35S25 76 25 56Z"
        class="rt-assistant-pet__fur"
    />
    <path
        d="M35 59c4-8 11-12 18-10 3 1 5 3 7 6 2-3 4-5 7-6 7-2 14 2 18 10 4 10-4 24-25 24S31 69 35 59Z"
        class="rt-assistant-pet__cream"
    />

    <g class="rt-assistant-pet__face">
        <ellipse cx="46" cy="56" rx="3.4" ry="4.2" class="rt-assistant-pet__eye" />
        <ellipse cx="74" cy="56" rx="3.4" ry="4.2" class="rt-assistant-pet__eye" />
        <path d="M56 65c2-2 6-2 8 0-1 3-3 4-4 4s-3-1-4-4Z" class="rt-assistant-pet__nose" />
        <path d="M60 69v3m0 0c-3 0-5-1-6-3m6 3c3 0 5-1 6-3" class="rt-assistant-pet__mouth" stroke-linecap="round" />
        <circle cx="39" cy="66" r="3" class="rt-assistant-pet__cheek" />
        <circle cx="81" cy="66" r="3" class="rt-assistant-pet__cheek" />
    </g>

    <g class="rt-assistant-pet__cap">
        <path d="M37 30c2-11 10-17 23-17s21 6 23 17H37Z" class="rt-assistant-pet__cap-crown" />
        <path d="M31 31c9-3 18-4 29-4s20 1 29 4c-2 5-8 8-14 8H45c-6 0-12-3-14-8Z" class="rt-assistant-pet__cap-brim" />
        <path d="M54 20h12v7H54v-7Z" class="rt-assistant-pet__cap-badge" />
        <path d="M58 21.5v4m4-4v4" class="rt-assistant-pet__cap-mark" stroke-linecap="round" />
    </g>

    <g class="rt-assistant-pet__paws">
        <path d="M38 91c6-1 10 2 11 8v8H35v-8c0-4 1-7 3-8Z" class="rt-assistant-pet__fur" />
        <path d="M82 91c-6-1-10 2-11 8v8h14v-8c0-4-1-7-3-8Z" class="rt-assistant-pet__fur" />
        <path d="M39 102h7m28 0h7" class="rt-assistant-pet__paw-line" stroke-linecap="round" />
    </g>
</svg>
