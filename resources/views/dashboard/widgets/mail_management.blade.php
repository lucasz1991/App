<p class="ops-kpi-val" style="font-size:30px;">{{ $data['pending'] }}</p>
<p class="ops-muted">Mails im Versand ausstehend</p>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $mail)
            <div class="ops-row"><span>{{ $mail->content['subject'] ?? ucfirst($mail->type) }}</span><span class="ops-muted">{{ count($mail->recipients ?? []) }} Empfänger</span></div>
        @empty
            <div class="ops-empty">Alles versendet.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Mailverwaltung öffnen →</a>
