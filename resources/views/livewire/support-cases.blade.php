<x-ui.page title="Hilfe & Supportfälle">
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">Anfragen, Rückfragen und Lösungen – mit Mitarbeiter- und Gerätebezug.</p>
            <div class="flex items-center gap-3">
                <details class="relative"><summary class="cursor-pointer rounded-lg border px-3 py-2">Filter</summary>
                    <div class="absolute right-0 z-20 w-64 rounded-xl border bg-white p-4 shadow-lg">
                        <label for="support-status">Status</label><select id="support-status" wire:model.live="statusFilter" class="mt-2 w-full rounded-lg border-slate-300"><option value="">Alle</option>
                            @foreach (\App\Services\Support\SupportCaseService::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </details>
                <a href="{{ route('support') }}" wire:navigate class="rounded-lg bg-rose-600 px-4 py-2 text-white">Neue Anfrage</a>
            </div>
        </div>
        <x-tables.table :columns="[['key'=>'subject','label'=>'Anfrage','width'=>'2fr'],['key'=>'user','label'=>'Mitarbeiter','width'=>'1fr'],['key'=>'device','label'=>'Gerät','width'=>'1fr'],['key'=>'status','label'=>'Status','width'=>'1fr']]" :items="$cases" row-view="components.tables.rows.support-case" />
        {{ $cases->links() }}
    </div>
    <x-dialog-modal wire:model="showCase" maxWidth="3xl">
        <x-slot name="title">{{ $detail['subject'] ?? 'Supportfall' }}</x-slot>
        <x-slot name="content">
            @if ($detail)
                <div class="space-y-4"><p class="text-sm text-slate-500">{{ $detail['status_label'] }} · {{ $detail['id'] }}</p>
                    @if ($detail['diagnostics'])<details class="rounded-lg border p-3"><summary>Freigegebene Diagnose</summary><dl class="mt-3 text-sm">@foreach ($detail['diagnostics'] as $key => $value)<div class="flex justify-between gap-4"><dt>{{ $key }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl></details>@endif
                    <ol class="max-h-96 space-y-3 overflow-y-auto" aria-label="Verlauf">@foreach ($detail['messages'] as $message)<li class="rounded-lg border p-3"><p class="text-xs text-slate-500">{{ $message['from_support'] ? 'IT-Support' : 'Mitarbeiter' }} · {{ $message['created_at'] }}</p><p class="mt-2 whitespace-pre-wrap">{{ $message['body'] }}</p></li>@endforeach</ol>
                    <form wire:submit="reply" class="space-y-2"><label for="support-reply">Antwort</label><textarea id="support-reply" wire:model="replyBody" maxlength="5000" class="w-full rounded-lg border-slate-300" rows="3"></textarea>@error('body')<p class="text-rose-600">{{ $message }}</p>@enderror<button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-rose-600 px-4 py-2 text-white">Antwort senden</button></form>
                    <details class="rounded-lg border p-3"><summary>Anhänge bewusst freigeben</summary>
                        <p class="mt-2 text-xs text-slate-500">PNG, JPG oder PDF, maximal 5 MB und drei Dateien pro Fall. Keine automatischen Screenshots. Vor Versand private Inhalte entfernen. Anhänge werden privat und verschlüsselt gespeichert, standardmäßig 30 Tage.</p>
                        <ul class="mt-2 text-sm">@foreach($detail['attachments'] as $file)<li><a href="{{ $file['url'] }}" class="underline">{{ $file['name'] }}</a></li>@endforeach</ul>
                        <form wire:submit="attachFile" class="mt-3 space-y-2"><label class="flex gap-2 text-sm"><input type="checkbox" wire:model.live="attachmentConfirmed">Ich habe die Datei geprüft. Die anschließende Auswahl darf sie für diesen Supportfall auf RailTime hochladen.</label><input type="file" wire:model="attachment" accept=".png,.jpg,.jpeg,.pdf" aria-label="Supportanhang auswählen" @disabled(!$attachmentConfirmed)><button type="submit" wire:loading.attr="disabled" class="rounded-lg border px-3 py-2">Anhang dem Fall hinzufügen</button>@error('attachment')<p class="text-red-700">{{ $message }}</p>@enderror</form>
                    </details>
                </div>
            @endif
        </x-slot>
        <x-slot name="footer"><div class="flex flex-wrap gap-2">
            @if ($canManage)<button wire:click="transition('in_progress')" class="rounded-lg border px-3 py-2">In Bearbeitung</button>@endif
            <button wire:click="transition('open')" class="rounded-lg border px-3 py-2">Wieder öffnen</button><button wire:click="transition('resolved')" class="rounded-lg border px-3 py-2">Gelöst</button><button wire:click="transition('closed')" class="rounded-lg border px-3 py-2">Fall schließen</button><button wire:click="$set('showCase', false)" class="rounded-lg border px-3 py-2">Zurück</button>
        </div></x-slot>
    </x-dialog-modal>
</x-ui.page>
