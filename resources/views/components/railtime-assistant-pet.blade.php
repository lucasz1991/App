<svg
    {{ $attributes->merge(['class' => 'rt-assistant-pet']) }}
    viewBox="0 0 120 120"
    fill="none"
    aria-hidden="true"
    focusable="false"
>
    <ellipse cx="60" cy="108" rx="34" ry="6" class="rt-assistant-pet__shadow" />

    <g class="rt-assistant-pet__leaf rt-assistant-pet__leaf--left" aria-hidden="true">
        <path d="M52 30C40 28 34 18 38 9c10 1 18 8 20 18l-6 3Z" class="rt-assistant-pet__leaf-body" />
        <path d="M41 13c5 4 9 8 13 15" class="rt-assistant-pet__leaf-vein" />
    </g>
    <g class="rt-assistant-pet__leaf rt-assistant-pet__leaf--right" aria-hidden="true">
        <path d="M68 30c12-2 18-12 14-21-10 1-18 8-20 18l6 3Z" class="rt-assistant-pet__leaf-body" />
        <path d="M79 13c-5 4-9 8-13 15" class="rt-assistant-pet__leaf-vein" />
    </g>

    <path
        d="M18 48c0-15 10-24 25-26h34c15 2 25 11 25 26v32c0 16-10 25-25 27H43c-15-2-25-11-25-27V48Z"
        class="rt-assistant-pet__capsule"
    />
    <path
        d="M24 49c0-12 8-20 21-21h30c13 1 21 9 21 21v29c0 13-8 20-21 22H45c-13-2-21-9-21-22V49Z"
        class="rt-assistant-pet__capsule-highlight"
    />

    <rect x="30" y="40" width="60" height="48" rx="16" class="rt-assistant-pet__bezel" />
    <rect x="34" y="44" width="52" height="40" rx="13" class="rt-assistant-pet__face-screen" />

    <g class="rt-assistant-pet__face">
        <g class="rt-assistant-pet__eye rt-assistant-pet__eye--left">
            <ellipse cx="48" cy="59" rx="5" ry="6.5" />
            <circle cx="46.5" cy="57" r="1.35" class="rt-assistant-pet__eye-shine" />
        </g>
        <g class="rt-assistant-pet__eye rt-assistant-pet__eye--right">
            <ellipse cx="72" cy="59" rx="5" ry="6.5" />
            <circle cx="70.5" cy="57" r="1.35" class="rt-assistant-pet__eye-shine" />
        </g>
        <ellipse cx="60" cy="73" rx="4.5" ry="2.6" class="rt-assistant-pet__mouth" />
    </g>

    <g class="rt-assistant-pet__feet" aria-hidden="true">
        <path d="M38 101c-5 2-9 6-9 10 7 1 13-1 18-6l-9-4Z" />
        <path d="M82 101c5 2 9 6 9 10-7 1-13-1-18-6l9-4Z" />
    </g>
</svg>
