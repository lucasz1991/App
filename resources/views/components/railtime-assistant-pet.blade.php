<svg
    {{ $attributes->merge(['class' => 'rt-assistant-pet']) }}
    viewBox="0 0 120 120"
    fill="none"
    aria-hidden="true"
    focusable="false"
>
    <g class="rt-assistant-pet__antenna">
        <path d="M60 30V17" class="rt-assistant-pet__antenna-stem" />
        <circle cx="60" cy="11" r="5.5" class="rt-assistant-pet__antenna-node" />
        <circle cx="60" cy="11" r="9" class="rt-assistant-pet__antenna-ring" />
    </g>

    <g class="rt-assistant-pet__signal" aria-hidden="true">
        <path d="M88 32c6 3 10 8 12 14" />
        <path d="M93 24c10 5 17 13 20 23" />
    </g>

    <path
        d="M21 61c0-23 17-38 39-38s39 15 39 38v20c0 22-16 34-39 34S21 103 21 81V61Z"
        class="rt-assistant-pet__body"
    />
    <path
        d="M28 61c0-18 14-30 32-30s32 12 32 30v15c0 18-13 28-32 28S28 94 28 76V61Z"
        class="rt-assistant-pet__body-highlight"
    />

    <g class="rt-assistant-pet__circuit" aria-hidden="true">
        <path d="M30 76h9l5 5h8" />
        <path d="M90 76h-9l-5 5h-8" />
        <circle cx="30" cy="76" r="2.2" />
        <circle cx="90" cy="76" r="2.2" />
        <circle cx="52" cy="81" r="2.2" />
        <circle cx="68" cy="81" r="2.2" />
    </g>

    <rect x="34" y="43" width="52" height="34" rx="16" class="rt-assistant-pet__face-panel" />
    <g class="rt-assistant-pet__face">
        <ellipse cx="49" cy="57" rx="4" ry="5" class="rt-assistant-pet__eye" />
        <ellipse cx="71" cy="57" rx="4" ry="5" class="rt-assistant-pet__eye" />
        <path d="M51 67c2.6 2.4 5.6 3.5 9 3.5s6.4-1.1 9-3.5" class="rt-assistant-pet__mouth" />
    </g>

    <g class="rt-assistant-pet__core" aria-hidden="true">
        <circle cx="60" cy="91" r="7" />
        <path d="M60 87v8M56 91h8" />
    </g>
</svg>
