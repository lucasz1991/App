<footer {{ $attributes->class('rt-ops-panel__footer') }}>
    <p class="rt-ops-panel__footer-note">{{ $note ?? '' }}</p>
    {{ $slot }}
</footer>
