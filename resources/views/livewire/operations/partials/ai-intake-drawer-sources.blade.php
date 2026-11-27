{{-- Formularbearbeitung, Reiter „Original & Anlagen“. --}}
<section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-sources" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-sources" @if($detailTab !== 'sources') hidden @endif>
    <h3>Nachrichten und Anlagen</h3>
    @forelse($selected->messages as $message)
        <details class="rt-intake-drawer__disclosure" @if($loop->first) open @endif wire:key="drawer-message-{{ $message->id }}">
            <summary>{{ $message->metadata['subject'] ?? 'Originaleingang' }} <span class="rt-intake-drawer__meta">{{ $message->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span></summary>
            <div class="rt-intake-drawer__disclosure-content">
                <p class="rt-intake-drawer__original">{{ $message->body }}</p>
                @if(($message->metadata['raw_archive_complete'] ?? true) === false || ($message->metadata['text_archive_complete'] ?? true) === false)<x-ui.feedback.alert type="warning" :dismissible="false">Dieser Eingang überschreitet die Verarbeitungsgrenzen. Das vollständige Original im Postfach manuell prüfen; hier ist ein begrenzter Ausschnitt archiviert.</x-ui.feedback.alert>@endif
                @if($message->raw_path)<x-ui.buttons.button-basic mode="link" :href="route('operations.ai-intake.original', $message->id)" data-no-navigate>{{ ($message->metadata['raw_archive_complete'] ?? true) ? 'Original herunterladen' : 'Archivierten Ausschnitt herunterladen' }}</x-ui.buttons.button-basic>@endif
            </div>
        </details>
    @empty<p class="ops-muted">Keine Nachrichten hinterlegt.</p>@endforelse
    @foreach($selected->attachments as $attachment)<div class="rt-intake-drawer__attachment" wire:key="drawer-attachment-{{ $attachment->id }}"><x-ui.buttons.button-basic mode="link" :href="route('operations.ai-intake.attachment', $attachment->id)" data-no-navigate><i class="far fa-paperclip" aria-hidden="true"></i>{{ $attachment->file_name }}</x-ui.buttons.button-basic><span class="rt-intake-drawer__meta">{{ number_format($attachment->file_size / 1024, 0, ',', '.') }} KB</span></div>@endforeach
</section>
