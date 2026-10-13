@if(($showCandidateHints ?? true) && !empty($contextHints['candidates']))
<section class="mt-3 text-sm" aria-label="Kundenhinweise">
    <h5 class="font-semibold">{{ !empty($contextHints['ambiguous']) ? 'Mehrere Kunden kommen infrage' : 'Vermuteter Kunde' }}</h5>
    @foreach($contextHints['candidates'] as $candidate)
        <p class="mt-2 break-words"><strong>{{ $candidate['name'] }}</strong><span class="block ops-muted">{{ implode(' · ', array_map(fn ($reason) => \App\Services\Operations\AiIntakeCustomerContextService::REASON_LABELS[$reason] ?? 'Zuordnung prüfen', $candidate['reasons'])) }}</span></p>
    @endforeach
    <p class="mt-2 ops-muted">Die Kundenzuordnung im nächsten Schritt bestätigen.</p>
</section>
@endif
@if(!empty($contextHints['history']))
<details class="ai-intake-context-hints mt-3 rounded-lg border border-rt-border p-3 text-sm dark:border-rt-dark-border">
    <summary class="cursor-pointer font-semibold"><i class="far fa-history mr-1" aria-hidden="true"></i>Vergleich mit früheren Vorgängen</summary>
    <p class="mt-2 ops-muted">Historische Hinweise zur Prüfung. Angaben aus der aktuellen Mail haben Vorrang.</p>
    @foreach($contextHints['history'] as $history)
        <section class="mt-3 space-y-2">
            <h5 class="font-semibold">{{ $history['customer']['name'] }}</h5>
            @foreach($history['orders'] as $order)
                <article class="border-t border-rt-border pt-2 dark:border-rt-dark-border">
                    <strong>Auftrag {{ $order['number'] }}</strong><p class="break-words">{{ $order['title'] }}</p>
                    <p class="ops-muted">{{ implode(' · ', array_filter([$order['location_name'] ?? '', $order['service_type'] ?? ''])) }}@if(!empty($order['required_staff'])) · {{ $order['required_staff'] }} {{ (int)$order['required_staff'] === 1 ? 'Person' : 'Personen' }} im früheren Auftrag @endif</p>
                </article>
            @endforeach
            @foreach($history['related_mails'] as $mail)
                <article class="border-t border-rt-border pt-2 dark:border-rt-dark-border"><strong>Frühere Mail</strong><p class="break-words">{{ $mail['subject'] }}</p><p class="ops-muted">{{ implode(' · ', array_map(fn ($reason) => ['mail_reference'=>'Antwortbezug','same_sender'=>'Gleicher Absender','shared_cc'=>'Gemeinsame CC-Adresse','shared_participant'=>'Gemeinsame Mailadresse','reference_match'=>'Gleiche Referenz','location_match'=>'Gleicher Einsatzort','role_match'=>'Gleiche Tätigkeit','title_match'=>'Ähnlicher Betreff','recent_customer_record'=>'Früherer Kundenvorgang','same_customer_mailbox'=>'Gleicher Kunde im Postfach'][$reason] ?? 'Möglicher Zusammenhang', $mail['reasons'] ?? [])) }}</p></article>
            @endforeach
            @if(empty($history['orders']) && empty($history['related_mails']))<p class="ops-muted">Keine passenden früheren Vorgänge im zugänglichen Bestand gefunden.</p>@endif
            @if(!empty($history['truncated']['orders']) || !empty($history['truncated']['related_mails']))<p class="ops-muted">Begrenzte Auswahl der passendsten früheren Vorgänge.</p>@endif
        </section>
    @endforeach
</details>
@endif
