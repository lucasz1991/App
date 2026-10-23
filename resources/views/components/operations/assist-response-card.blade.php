@props(['card', 'cardId', 'emptyLabel' => null])

@php
    $state = $card['state'] ?? 'open';
    $staffing = ($card['presentation'] ?? '') === 'staffing'
        || (isset($card['payload']) && ($card['title'] ?? '') === 'Besetzungsvorschlag');
    $title = $card['display_title'] ?? $card['title'];
    $note = str_replace('**', '', $card['note'] ?? '');
    $summaryNote = $card['summary_note'] ?? ($staffing ? 'Übernahme als „Angefragt“' : null);
@endphp
<section {{ $attributes->class(['rt-ai-assist__card', 'rt-ai-response-card']) }} data-state="{{ $state }}" data-presentation="{{ $staffing ? 'staffing' : 'default' }}" aria-labelledby="{{ $cardId }}-title">
    <header class="rt-ai-response-card__head">
        <i class="far {{ $staffing ? 'fa-calendar-alt' : ($card['icon'] ?? 'fa-sparkles') }}" aria-hidden="true"></i>
        <div>
            <strong id="{{ $cardId }}-title">{{ $title }}</strong>
            @if(!empty($card['subtitle']))<span>{{ $card['subtitle'] }}</span>@endif
        </div>
        @if(in_array($state, ['done', 'dismissed', 'undone'], true))
            <span class="rt-ai-assist__badge" data-tone="{{ $state === 'done' ? 'ok' : 'neutral' }}">{{ ['done' => 'Übernommen', 'dismissed' => 'Verworfen', 'undone' => 'Zurückgenommen'][$state] }}</span>
        @endif
    </header>

    @if(!empty($card['rows']))
        <div class="rt-ai-response-card__list" role="region" tabindex="0" aria-label="{{ $title }} – scrollbare Liste">
            <ul class="rt-ai-response-card__rows">
                @foreach($card['rows'] as $index => $row)
                    @php
                        // Existing session cards predate structured display fields.
                        $legacyDetail = $staffing && !isset($row['heading']) && str_contains($row['label'], ' · ');
                        $heading = $row['heading'] ?? ($legacyDetail ? \Illuminate\Support\Str::beforeLast($row['label'], ' · ') : $row['label']);
                        $detail = $row['detail'] ?? ($legacyDetail ? \Illuminate\Support\Str::afterLast($row['label'], ' · ') : '');
                        $small = $row['small'] ?? '';
                        $quietStatus = $staffing && $small === 'Keine bekannten Konflikte';
                        $hasDetails = !empty($row['why']) || $quietStatus;
                    @endphp
                    <li class="rt-ai-response-card__row" data-has-details="{{ $hasDetails ? 'true' : 'false' }}" wire:key="{{ $cardId }}-row-{{ $index }}">
                        <div class="rt-ai-response-card__subject">
                            <strong>{{ $heading }}</strong>
                            @if($detail !== '')<span>{{ $detail }}</span>@endif
                        </div>
                        <i class="far fa-arrow-right rt-ai-response-card__arrow" aria-hidden="true"></i>
                        <div class="rt-ai-response-card__value">
                            <strong>{{ $row['value'] }}</strong>
                            @if($small !== '' && !$quietStatus)<span>{{ $small }}</span>@endif
                        </div>
                        @if($hasDetails)
                            <details class="rt-ai-response-card__why">
                                <summary aria-label="Begründung zu {{ $heading }} – {{ $row['value'] }}" title="Begründung anzeigen">
                                    <i class="far fa-info-circle" aria-hidden="true"></i><span class="sr-only">Begründung anzeigen</span>
                                </summary>
                                <div class="rt-ai-response-card__reasons">
                                    @if($quietStatus)<p>{{ $small }}</p>@endif
                                    @if(!empty($row['why']))<ul>@foreach($row['why'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
                                </div>
                            </details>
                        @endif
                        @if(!empty($row['href']))<a class="rt-ai-response-card__record" href="{{ $row['href'] }}" wire:navigate>Vorgang prüfen <i class="far fa-arrow-right" aria-hidden="true"></i></a>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif($emptyLabel)
        <p class="rt-ai-response-card__empty">{{ $emptyLabel }}</p>
    @endif

    @if(!empty($card['body']))
        <details class="rt-ai-response-card__body" open><summary>Konkrete Kundennachricht prüfen</summary><p>{{ $card['body'] }}</p></details>
    @endif
    @if($summaryNote && $state === 'open')
        <details class="rt-ai-response-card__note">
            <summary><i class="far fa-info-circle" aria-hidden="true"></i><span>{{ $summaryNote }}</span></summary>
            @if($note !== '')<p>{{ $note }}</p>@endif
        </details>
    @elseif($note !== '')
        <p class="rt-ai-response-card__note-text">{{ $note }}</p>
    @endif
    @if(!empty($card['expired']))<p class="rt-ai-response-card__note-text" role="status">Diese Auswertung ist abgelaufen. Bitte erneut auswerten.</p>@endif
    @foreach($card['failed'] ?? [] as $failure)
        <p class="rt-ai-assist__card-failed" role="alert">Nicht übernommen – {{ $failure }}</p>
    @endforeach
    @isset($actions)
        @if($actions->isNotEmpty())<footer class="rt-ai-response-card__actions">{{ $actions }}</footer>@endif
    @endisset
</section>
