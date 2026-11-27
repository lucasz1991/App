{{-- Formularbearbeitung eines Eingangs (Seitenleiste): Kopf, Reiter und Reiterinhalte. Ein x-data-Scope über alle Reiter. --}}
@php
    $currentProposals = $selected->proposals->where('source_revision', $selected->source_revision)->where('status', '!=', 'stale');
    $customerMailSuppressed = $selected->processing_mode === 'manual' || $selected->proposals->contains(fn ($record) => ($record->payload['review_confirmation']['customer_communication'] ?? null) === 'off');
@endphp
<div class="rt-intake-drawer" data-intake-drawer data-active-tab="{{ $detailTab }}"
    x-data="{ editorFocusKey: null }"
    x-effect="const key = $wire.manualEditorOpen ? 'new-' + $wire.selectedId : ($wire.editingProposalId !== null ? 'position-' + $wire.editingProposalId : ($wire.customerEditorOpen ? 'customer-' + $wire.selectedId : ($wire.planningProposalId !== null ? 'planning-' + $wire.planningProposalId : null))); const changed = key !== editorFocusKey; editorFocusKey = key; if (changed &amp;&amp; key &amp;&amp; $wire.detailTab === 'review') $nextTick(() => { const region = key.startsWith('customer-') ? $el.querySelector('.rt-intake-drawer__customer-form') : (key.startsWith('planning-') ? $el.querySelector('[data-ai-intake-planning-editor]') : $el.querySelector('[data-intake-position-form]')); const target = region?.querySelector('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[role=combobox]:not([disabled]),button:not([disabled])'); target?.focus({ preventScroll: true }); target?.scrollIntoView({ block: 'nearest', behavior: 'auto' }); });">
    <div class="rt-intake-drawer__navigation">
        <x-operations.panel.tabs class="rt-intake-drawer__tabs" label="Eingang bearbeiten" :id-prefix="'intake-drawer-'.$this->getId()" action="setDetailTab" model="detailTab" :active="$detailTab" :tabs="['review' => ['label' => 'Bearbeiten', 'icon' => 'far fa-pen'], 'sources' => ['label' => 'Original & Anlagen', 'icon' => 'far fa-paperclip'], 'communication' => ['label' => 'Verlauf', 'icon' => 'far fa-history'], 'details' => ['label' => 'Details', 'icon' => 'far fa-list']]" />
    </div>

    <header class="rt-intake-drawer__context">
        <p class="rt-intake-drawer__meta">AI-{{ str_pad((string) $selected->id, 6, '0', STR_PAD_LEFT) }} · {{ ['email' => 'E-Mail', 'manual' => 'Formular', 'text' => 'Text', 'file' => 'Datei', 'audio' => 'Sprachaufnahme'][$selected->source_type] ?? 'Eingang' }}@if($selected->created_at) · {{ $selected->created_at->timezone('Europe/Berlin')->format('d.m.Y, H:i') }}@endif</p>
        <h2 class="rt-intake-drawer__title" tabindex="-1" data-intake-drawer-heading>{{ $selected->title ?: 'Eingang prüfen' }}</h2>
        <section class="rt-intake-drawer__customer" data-intake-customer-assignment aria-label="Kunde">
            <span class="rt-intake-drawer__customer-avatar" aria-hidden="true"><i class="far fa-building"></i></span>
            <div class="rt-intake-drawer__customer-identity"><span>Kunde</span><strong>{{ $selected->customer?->company_name ?: 'Noch nicht zugeordnet' }}</strong></div>
        </section>
    </header>

    @if($drawerState['stale'])<div class="rt-intake-drawer__notice" role="status">Dieser Eingang oder seine Einstellungen wurden inzwischen geändert. Vor einer Änderung den aktuellen Stand laden.</div>@endif

    @include('livewire.operations.partials.ai-intake-drawer-review')
    @include('livewire.operations.partials.ai-intake-drawer-details')
    @include('livewire.operations.partials.ai-intake-drawer-sources')
    @include('livewire.operations.partials.ai-intake-drawer-communication')
</div>
