<x-customer-portal-auth-layout title="Wiederherstellungscodes" kicker="Zugangssicherheit">
    <p class="rt-customer-portal__auth-hint">Jetzt sicher speichern. Jeder Code kann einmal verwendet werden.</p>
    <div class="rt-customer-portal__auth-code-grid" aria-label="Einmalige Wiederherstellungscodes">
        @foreach($codes as $code)<code class="rt-customer-portal__auth-code">{{ $code }}</code>@endforeach
    </div>
    <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" href="/kundenportal" data-no-navigate mode="primary">
        <span class="rt-customer-portal__button-label">Zum Kundenportal</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
    </x-ui.buttons.button-basic>
</x-customer-portal-auth-layout>
