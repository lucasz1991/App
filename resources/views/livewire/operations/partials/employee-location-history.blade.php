<details>
    <summary class="min-h-11 cursor-pointer" wire:click="$set('historyOpen', true)">Mein Standortverlauf · letzte sieben Tage</summary>
    @if($historyOpen)
        <div data-table-html-frame class="overflow-x-auto mt-3">
            <table class="w-full">
                <caption class="sr-only">Eigene gespeicherte Standortpunkte</caption>
                <thead><tr><th scope="col">Messzeit</th><th scope="col">Koordinaten</th><th scope="col">Genauigkeit</th></tr></thead>
                <tbody>
                    @forelse($locationStatus['history'] as $point)
                        <tr wire:key="own-location-{{ $point->id }}">
                            <td class="p-2">{{ $point->measured_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }}</td>
                            <td class="p-2">{{ number_format($point->position['latitude'], 6, ',', '.') }}, {{ number_format($point->position['longitude'], 6, ',', '.') }}</td>
                            <td class="p-2">{{ number_format($point->accuracy_m, 0, ',', '.') }} m</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-2">Keine Punkte vorhanden.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.buttons.button-basic wire:click="deleteHistory" wire:confirm="Alle eigenen Standortpunkte löschen und die heutige Freigabe widerrufen?" class="min-h-11">Meinen Verlauf löschen</x-ui.buttons.button-basic>
    @endif
</details>
