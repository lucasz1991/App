{{-- Formularbearbeitung, Reiter „Verlauf“: Kundennachrichten und Verarbeitung. --}}
<section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-communication" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-communication" @if($detailTab !== 'communication') hidden @endif>
    <h3>Kundennachrichten und Verarbeitung</h3><p class="ops-muted">{{ $selected->question_round }} Rückfragerunden.</p>
    @if($customerMailSuppressed)<p class="rt-intake-drawer__notice" role="status">Kundenkommunikation ist für diesen manuell übernommenen Vorgang ausgeschaltet. Vorhandene Nachrichten bleiben im Verlauf erhalten.</p>@endif
    @forelse($selected->deliveries as $delivery)
        <details class="rt-intake-drawer__disclosure" wire:key="drawer-delivery-{{ $delivery->id }}"><summary>{{ \App\Models\AiIntakeDelivery::TYPE_LABELS[$delivery->message_type ?? 'clarification'] ?? 'Kundennachricht' }} <span class="rt-intake-drawer__meta">{{ ($delivery->metadata['manual_mailbox_test'] ?? false) ? 'Testentwurf · ohne Versand' : (\App\Models\AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen') }}</span></summary><div class="rt-intake-drawer__disclosure-content">
            <p class="rt-intake-drawer__original">{{ $delivery->body }}</p>
            @if($customerMailSuppressed && in_array($delivery->status, ['draft', 'pending'], true))<p class="ops-muted">Der Versand ist für diesen Vorgang gesperrt.</p>
            @elseif($delivery->status === 'draft' && !($delivery->metadata['manual_mailbox_test'] ?? false))<p class="ops-muted">Empfänger und Vorlage vor dem Versand im AI-Assistenten persönlich freigeben oder verwerfen.</p>
            @elseif($delivery->metadata['manual_mailbox_test'] ?? false)<p class="ops-muted">Dieser Testentwurf bleibt ohne Versand.</p>
            @elseif($delivery->status === 'unknown')<p class="ops-muted">Der Versand wird nicht automatisch wiederholt. Bitte im Dispositionspostfach prüfen, ob die Nachricht gesendet wurde.</p>@endif
        </div></details>
    @empty<p class="ops-muted">Keine ausgehenden Kundennachrichten hinterlegt.</p>@endforelse
    <h4 class="rt-intake-drawer__section-title">Verarbeitung</h4>
    @forelse($selected->runs as $run)<p class="rt-intake-drawer__run">{{ $run->started_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ ['running'=>'Analyse läuft','completed'=>'Analyse abgeschlossen','succeeded'=>'Verarbeitung abgeschlossen','failed'=>'Analyse prüfen','stale'=>'Stand inzwischen geändert'][$run->status] ?? 'Verarbeitung protokolliert' }}</p>@empty<p class="ops-muted">Noch keine Verarbeitung protokolliert.</p>@endforelse
</section>
