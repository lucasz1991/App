<div>
    @section('title')
        {{ __('Exporte') }}
    @endsection

    <div class="flex justify-between items-center mb-8">
        <!-- Titel links -->
        <h1 class="text-xl font-semibold">Datenexport</h1>

        <!-- Button mit Dropdown rechts -->
        <div class="relative" x-data="{ open: false }">
            <x-button @click="open = !open" class="btn-xs text-sm">
                ⚙️ Auto-Export's
            </x-button>

            <div x-show="open" x-cloak class="absolute right-0 z-20 mt-2 w-72 rounded-lg border border-rt-border bg-rt-surface p-4 text-rt-text shadow-lg dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text">
                <h3 class="mb-2 text-lg font-semibold">Einstellungen</h3>
                <!-- Hinweis-Alert -->
                <div class="mb-4 p-3 bg-blue-100 border border-blue-300 text-blue-800 text-sm rounded">
                    Automatische Exporte werden ( monatlich am 1 des Monats um 02:00 Uhr für den beendeten Monat ) an die unten angegebene E-Mail und an die Admin E-Mail gesendet.
                </div>

                <!-- Checkbox für automatische Exporte -->
                <div class="mb-4">
                    <x-ui.forms.checkbox id="autoExport" wire:model.live="autoExport" label="Automatische Exporte aktivieren" />
                </div>

                <!-- E-Mail-Adresse für automatische Exporte -->
                <div class="mb-4">
                    <x-ui.forms.label for="exportEmail" value="E-Mail für Exporte:" />
                    <x-ui.forms.input type="email" id="exportEmail" wire:model.live="exportEmail" />
                </div>

                <!-- Speichern-Button -->
                <x-button wire:click="saveExportSettings" class="btn-xs text-sm">
                    Speichern
                </x-button>
            </div>
        </div>
    </div>



    <x-ui.forms.label for="month" value="Monat auswählen:" />
    <div class="flex space-x-2 mb-5">
        <!-- Monat Dropdown -->
        <x-ui.forms.select id="month" class="w-52" wire:model.live="selectedMonthMonth">
                <option value="">
                    Bitte Monat auswählen...
                </option>
            @foreach(range(1, 12) as $month)
                <option value="{{ str_pad($month, 2, '0', STR_PAD_LEFT) }}">
                    {{ date('F', mktime(0, 0, 0, $month, 1)) }}
                </option>
            @endforeach
        </x-ui.forms.select>

        <!-- Jahr Dropdown -->
        <x-ui.forms.select id="year" class="w-40" wire:model.live="selectedMonthYear">
                <option value="">
                    Bitte Jahr auswählen...
                </option>
            @foreach(range(date('Y') - 3, date('Y')) as $year)
                <option value="{{ $year }}">{{ $year }}</option>
            @endforeach
        </x-ui.forms.select>
    </div>

        <!-- Export Buttons -->
        <x-button wire:click="exportBookings" class="btn-xs text-sm">
            📦 Regalbuchungen 
        </x-button>
        <x-button wire:click="exportBookingExtends" class="btn-xs text-sm">
            🔄 Verlängerungen 
        </x-button>
        <x-button wire:click="exportPayouts" class="btn-xs text-sm">
            💰 Auszahlungen 
        </x-button>
        <x-button wire:click="exportCustomers" class="btn-xs text-sm">
            🧑‍🤝‍🧑 Kunden 
        </x-button>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                Livewire.on('downloadCsv', (data) => {
                    try {
                        const encodedCsv = data[0][0]; 
                        const filename = data[0][1]; 
                        const binaryString = Uint8Array.from(
                            atob(encodedCsv).split("").map(c => c.charCodeAt(0))
                        );
                        const csvString = new TextDecoder("utf-8").decode(binaryString);
                        const blob = new Blob([csvString], { type: "text/csv;charset=utf-8;" });
                        const link = document.createElement("a");
                        link.href = URL.createObjectURL(blob);
                        link.setAttribute("download", filename);
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } catch (error) {
                        console.error("Fehler beim Dekodieren der CSV-Datei:", error);
                    }
                });
            });
        </script>
</div>
