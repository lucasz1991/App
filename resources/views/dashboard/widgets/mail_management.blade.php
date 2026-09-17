<span class="widget-primary-val">{{ $data['pending'] }}</span>
<span class="widget-primary-lbl">Mails im Versand ausstehend</span>
@if($rows === 2)
    <div class="widget-detail">
        @forelse($data['recent'] as $mail)
            <div class="ops-row"><span>{{ $mail->content['subject'] ?? ucfirst($mail->type) }}</span><span class="ops-muted">{{ count($mail->recipients ?? []) }} Empfänger</span></div>
        @empty
            <div class="ops-empty">Alles versendet.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Mailverwaltung öffnen →</a>
