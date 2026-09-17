<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['pending'] === 0 ? 'ok' : 'warn' }}"><i data-feather="send"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['pending'] }}</span>
        <span class="ops-kpi-lbl">Mails im Versand ausstehend</span>
    </span>
</div>
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
