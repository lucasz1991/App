<svg
    {{ $attributes->merge(['class' => 'rt-assistant-pet']) }}
    viewBox="0 0 120 120"
    fill="none"
    aria-hidden="true"
    focusable="false"
>
    <ellipse cx="60" cy="108" rx="31" ry="7" class="rt-assistant-pet__shadow" />

    <g class="rt-assistant-pet__aura" aria-hidden="true">
        <circle cx="18" cy="45" r="3" />
        <circle cx="102" cy="39" r="2.5" />
        <circle cx="97" cy="72" r="2" />
    </g>

    <g class="rt-assistant-pet__ear rt-assistant-pet__ear--left">
        <path d="M35 42C23 38 13 27 15 19c9-2 22 5 29 16l-9 7Z" class="rt-assistant-pet__ear-body" />
        <path d="M33 37c-6-3-11-8-13-13 5 1 11 5 16 11l-3 2Z" class="rt-assistant-pet__ear-inner" />
    </g>
    <g class="rt-assistant-pet__ear rt-assistant-pet__ear--right">
        <path d="M85 42c12-4 22-15 20-23-9-2-22 5-29 16l9 7Z" class="rt-assistant-pet__ear-body" />
        <path d="M87 37c6-3 11-8 13-13-5 1-11 5-16 11l3 2Z" class="rt-assistant-pet__ear-inner" />
    </g>

    <path
        d="M23 64c0-27 16-45 37-45s37 18 37 45v14c0 23-14 36-37 36S23 101 23 78V64Z"
        class="rt-assistant-pet__body"
    />
    <path
        d="M31 63c0-21 12-36 29-36s29 15 29 36v14c0 19-10 29-29 29S31 96 31 77V63Z"
        class="rt-assistant-pet__body-highlight"
    />
    <path
        d="M41 82c3-8 10-12 19-12s16 4 19 12c2 7-4 18-19 18S39 89 41 82Z"
        class="rt-assistant-pet__belly"
    />

    <g class="rt-assistant-pet__tuft" aria-hidden="true">
        <ellipse cx="51" cy="24" rx="5" ry="11" transform="rotate(-20 51 24)" />
        <ellipse cx="60" cy="20" rx="5" ry="12" />
        <ellipse cx="69" cy="24" rx="5" ry="11" transform="rotate(20 69 24)" />
    </g>

    <g class="rt-assistant-pet__flipper rt-assistant-pet__flipper--left" aria-hidden="true">
        <path d="M28 70c-8 2-12 9-10 15 5 1 10-2 14-8l-4-7Z" />
    </g>
    <g class="rt-assistant-pet__flipper rt-assistant-pet__flipper--right" aria-hidden="true">
        <path d="M92 70c8 2 12 9 10 15-5 1-10-2-14-8l4-7Z" />
    </g>

    <g class="rt-assistant-pet__face">
        <g class="rt-assistant-pet__eye rt-assistant-pet__eye--left">
            <ellipse cx="47" cy="56" rx="7" ry="9" />
            <circle cx="44.5" cy="52.5" r="2" class="rt-assistant-pet__eye-shine" />
        </g>
        <g class="rt-assistant-pet__eye rt-assistant-pet__eye--right">
            <ellipse cx="73" cy="56" rx="7" ry="9" />
            <circle cx="70.5" cy="52.5" r="2" class="rt-assistant-pet__eye-shine" />
        </g>
        <ellipse cx="37" cy="68" rx="7" ry="3.5" class="rt-assistant-pet__cheek" />
        <ellipse cx="83" cy="68" rx="7" ry="3.5" class="rt-assistant-pet__cheek" />
        <ellipse cx="60" cy="68" rx="5" ry="3.2" class="rt-assistant-pet__mouth" />
        <path d="M57.5 68.2c1.5 1.7 3.5 1.7 5 0" class="rt-assistant-pet__tongue" />
    </g>

    <g class="rt-assistant-pet__gem" aria-hidden="true">
        <path d="m60 32 5 5-5 6-5-6 5-5Z" />
        <circle cx="60" cy="37" r="9" class="rt-assistant-pet__gem-ring" />
    </g>

    <g class="rt-assistant-pet__feet" aria-hidden="true">
        <ellipse cx="43" cy="105" rx="13" ry="6" transform="rotate(-8 43 105)" />
        <ellipse cx="77" cy="105" rx="13" ry="6" transform="rotate(8 77 105)" />
    </g>
</svg>
