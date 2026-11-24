<div class="ops-stack" wire:poll.visible.20s>
    <x-ui.page title="Nachweis zum Dienst" :description="$shift->title">
        <section class="ops-panel ops-stack">
            <h2>{{ $shift->title }}</h2>
            @if($sections->isNotEmpty())<x-operations.field label="Leistung" type="select" model="sectionId" :live="true"><option value="">Gesamtdienst</option>@foreach($sections as $id => $item)<option value="{{ $id }}">{{ $item['section_label'] }} · {{ $item['title'] }}</option>@endforeach</x-operations.field>@endif
            <dl class="ops-meta">
                <div><dt>Tätigkeit</dt><dd>{{ $rule['title'] }}</dd></div>
                <div><dt>Dienstbeginn</dt><dd>{{ $shift->starts_at->timezone($shift->timezone)->format('d.m.Y H:i') }}</dd></div>
                <div><dt>Auftrag</dt><dd>{{ $shift->order->order_number ?: $shift->order->title }}</dd></div>
                <div><dt>Erforderlicher Beleg</dt><dd>{{ $rule['proof_type'] }} · {{ $rule['individual_proof'] ? 'Einzelnachweis' : 'Gemeinsamer Dienstnachweis' }}</dd></div>
            </dl>
            @if($proof)<x-operations.status :value="$proof->status" />@else<p>Der Dienstnachweis ist offen.</p>@endif
            @if(!$proof || $proof->user_id == auth()->id())
                <x-ui.buttons.button-basic type="button" wire:click="openProof" mode="primary" wire:loading.attr="disabled">{{ $proof ? 'Nachweis öffnen' : 'Nachweis erfassen' }}</x-ui.buttons.button-basic>
            @else<p>Ein Teammitglied bearbeitet den gemeinsamen Beleg.</p>@endif
        </section>
        @if($opened && $proof?->id === $opened && $proof->user_id == auth()->id())
            <livewire:operations.operations-enhancements :personal="true" :embedded="true" :modal-only="true" :initial-record-id="$opened" :key="'duty-proof-'.$opened.'-'.$openCount" />
        @endif
    </x-ui.page>
</div>
