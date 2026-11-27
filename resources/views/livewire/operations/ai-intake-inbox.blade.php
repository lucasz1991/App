<section @class(['ops-stack min-w-0', 'ai-intake-panel' => $embedded]) data-ai-intake-inbox data-embedded="{{ $embedded ? 'true' : 'false' }}" data-detail-tab="{{ $detailTab }}" data-mode="{{ $captureOpen ? 'capture' : ($detailOpen && $selectedId ? 'detail' : 'list') }}" wire:poll.visible.10s
    x-data="{ panelMode: null }" @if($embedded) x-effect="const next = $wire.captureOpen ? 'capture' : ($wire.detailOpen && $wire.selectedId ? 'detail-' + $wire.selectedId : 'list'); if (next !== panelMode) { const previous = panelMode; panelMode = next; $nextTick(() => { if (next !== 'list') $refs.intakePanelHeading?.focus(); else if (previous?.startsWith('detail-')) $refs.intakeList?.querySelector('[data-intake-id=&quot;' + previous.slice(7) + '&quot;]')?.focus(); }); }" @endif>
    @if($embedded)
        @include('livewire.operations.partials.ai-intake-panel')
    @else
        @unless($modalOnly)
            @include('livewire.operations.partials.ai-intake-page-list')
        @endunless
        <x-operations.modal wire:model.live="captureOpen" title="Eingang erfassen" max-width="3xl">
            <x-operations.feedback :show-offline="false" />
            @include('livewire.operations.partials.ai-intake-composer', ['composerMode' => 'capture', 'maxCharacters' => 20000, 'maxAttachmentCount' => $limits['max_attachment_count'], 'maxAudioKilobytes' => $limits['max_audio_kilobytes']])
        </x-operations.modal>
        <x-operations.modal wire:model.live="detailOpen" title="Eingang bearbeiten" max-width="4xl" variant="drawer">
            @if($selected)
                @include('livewire.operations.partials.ai-intake-drawer')
            @endif
            <x-slot:footer>
                @if($selected)
                    @include('livewire.operations.partials.ai-intake-drawer-footer')
                @endif
            </x-slot:footer>
        </x-operations.modal>
    @endif
    @if($customerDraftOpen && $selectedId)<livewire:operations.customers :embedded="true" :modal-only="true" :start-creating="true" :workspace-revision="$selectedRevision" :draft-intake-id="$selectedId" :key="'ai-customer-draft-'.$selectedId.'-'.$selectedRevision" />@endif
</section>
