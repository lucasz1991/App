<svg
    {{ $attributes->merge(['class' => 'rt-assistant-pet']) }}
    viewBox="0 0 120 120"
    fill="none"
    aria-hidden="true"
    focusable="false"
>
    <ellipse cx="60" cy="108" rx="34" ry="6" class="rt-assistant-pet__shadow" />

    <g class="rt-assistant-pet__leaf rt-assistant-pet__leaf--left" aria-hidden="true">
        <path d="M48 31C35 31 27 23 29 12c11-1 20 5 25 16l-6 3Z" class="rt-assistant-pet__leaf-body" />
        <path d="M34 17c7 2 12 6 17 12" class="rt-assistant-pet__leaf-vein" />
    </g>
    <g class="rt-assistant-pet__leaf rt-assistant-pet__leaf--right" aria-hidden="true">
        <path d="M72 31c13 0 21-8 19-19-11-1-20 5-25 16l6 3Z" class="rt-assistant-pet__leaf-body" />
        <path d="M86 17c-7 2-12 6-17 12" class="rt-assistant-pet__leaf-vein" />
    </g>

    <path
        d="M18 53c0-19 15-32 34-34 5-1 11-1 16 0 19 2 34 15 34 34v24c0 19-14 31-34 33-5 1-11 1-16 0-20-2-34-14-34-33V53Z"
        class="rt-assistant-pet__capsule"
    />
    <path
        d="M23 52c0-16 13-27 30-29 5-1 10-1 15 0 17 2 29 13 29 29v24c0 16-12 27-29 29-5 1-10 1-15 0-17-2-30-13-30-29V52Z"
        class="rt-assistant-pet__capsule-highlight"
    />

    <path
        d="M32 52c2-11 12-18 28-18s26 7 28 18v17c-2 12-12 19-28 19s-26-7-28-19V52Z"
        class="rt-assistant-pet__face-screen"
    />

    <g class="rt-assistant-pet__arms" aria-hidden="true">
        <path d="M24 63c-8 1-13 6-12 13 6 2 11 0 16-5l-4-8Z" />
        <path d="M96 63c8 1 13 6 12 13-6 2-11 0-16-5l4-8Z" />
    </g>

    <g class="rt-assistant-pet__face">
        <g class="rt-assistant-pet__eye rt-assistant-pet__eye--left">
            <ellipse cx="48" cy="59" rx="5" ry="6.5" />
            <circle cx="46.5" cy="57" r="1.35" class="rt-assistant-pet__eye-shine" />
        </g>
        <g class="rt-assistant-pet__eye rt-assistant-pet__eye--right">
            <ellipse cx="72" cy="59" rx="5" ry="6.5" />
            <circle cx="70.5" cy="57" r="1.35" class="rt-assistant-pet__eye-shine" />
        </g>
        <circle cx="43" cy="69" r="3.2" class="rt-assistant-pet__cheek" />
        <circle cx="77" cy="69" r="3.2" class="rt-assistant-pet__cheek" />
        <path d="M55 73c2 3 8 3 10 0" class="rt-assistant-pet__mouth" />
    </g>

    <g class="rt-assistant-pet__feet" aria-hidden="true">
        <path d="M39 101c-6 2-10 6-10 10 8 1 14-1 19-6l-9-4Z" />
        <path d="M81 101c6 2 10 6 10 10-8 1-14-1-19-6l9-4Z" />
    </g>
</svg>
