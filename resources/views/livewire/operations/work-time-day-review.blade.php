<section class="rt-day-review" aria-labelledby="day-review-{{ $entryId }}" x-data="{}"
    x-on:day-review-focus="$nextTick(() => $refs[$event.detail.target]?.focus())">
    @if($confirmed)
        <p id="day-review-{{ $entryId }}" role="status">Tagesbeleg bestätigt und zur Prüfung eingereicht.</p>
    @else
        <header class="rt-day-review__heading">
            <p>{{ $entry->starts_at->format('d.m.Y') }}</p>
            <h2 id="day-review-{{ $entryId }}">Tagesbeleg prüfen</h2>
        </header>
        @if($entry->status === 'returned' && $entry->review_note)
            <p class="rt-day-review__notice"><strong>Rückmeldung zur Korrektur</strong> {{ $entry->review_note }}</p>
        @endif
        @php
            $duration = [\App\Support\Operations\OperationsDateTime::class, 'duration'];
            $net = $summary['net_seconds'];
            $standard = $summary['standard_seconds'];
            $sameDate = $entry->starts_at->isSameDay($entry->ends_at);
        @endphp
        <div class="rt-day-review__totals">
            <div>
                <strong class="rt-day-review__net">{{ $duration($net) }}</strong>
                <span>Nettoarbeitszeit</span>
            </div>
            <div>
                @if($standard !== null && $net > 0)
                    <meter class="rt-day-review__bar" aria-label="Anteil Standardarbeit an der Nettoarbeitszeit" min="0" max="{{ $net }}" value="{{ $standard }}"></meter>
                @endif
                <dl class="rt-day-review__split">
                    <div><dt>Standardarbeit</dt><dd>{{ $standard === null ? 'Zuordnung offen' : $duration($standard) }}</dd></div>
                    @foreach($summary['duties'] as $duty)
                        <div><dt>{{ $duty['title'] }}</dt><dd>{{ $duty['performed'] ? $duration($duty['seconds']) : 'Ausgefallen' }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </div>
        <dl class="rt-day-review__times">
            <div><dt>Arbeitszeit</dt><dd>{{ $entry->starts_at->format($sameDate ? 'H:i' : 'd.m. H:i') }}–{{ $entry->ends_at->format($sameDate ? 'H:i' : 'd.m. H:i') }}</dd></div>
            <div><dt>{{ count($breaks) > 1 ? 'Pausen' : 'Pause' }}</dt><dd>{{ $breaks ? implode(' · ', $breaks) : ($entry->pause_seconds ? $duration($entry->pause_seconds) : 'Keine Pause') }}</dd></div>
        </dl>
        @if($summary['issue'])
            <p class="rt-day-review__notice" role="status">{{ $summary['issue'] }}</p>
            @if($needsBreakDetails)
                <div><x-ui.buttons.button-basic type="button" wire:click="$parent.openSections({{ $entryId }})">Arbeitsabschnitte prüfen</x-ui.buttons.button-basic></div>
            @endif
        @endif
        @if($errors->any())
            <p class="rt-day-review__notice" id="day-review-error-{{ $entryId }}" role="alert" tabindex="-1" x-ref="reviewError">{{ $errors->first() }}</p>
        @endif
        @if($editing)
            <form wire:submit="apply" class="rt-day-review__editor" aria-labelledby="day-review-edit-{{ $entryId }}"
                @if($errors->any()) aria-describedby="day-review-error-{{ $entryId }}" @endif>
                <h3 id="day-review-edit-{{ $entryId }}" tabindex="-1" x-init="$nextTick(() => $el.focus())">Zuordnung ändern</h3>
                @foreach($summary['duties'] as $duty)
                    @php($id = $duty['assignment_id'])
                    <fieldset wire:key="assignment-{{ $entryId }}-{{ $id }}">
                        <legend>{{ $duty['title'] }}</legend>
                        <label class="rt-day-review__performed"><input type="checkbox" wire:model.live="fields.{{ $id }}.performed"> Dienst geleistet</label>
                        @if($fields[$id]['performed'] ?? false)
                            <div class="rt-day-review__inputs">
                                <label>Dienstbeginn<input type="datetime-local" step="1" required wire:model="fields.{{ $id }}.starts_at"></label>
                                <label>Dienstende<input type="datetime-local" step="1" required wire:model="fields.{{ $id }}.ends_at"></label>
                            </div>
                        @else
                            <span class="rt-day-review__caption">Dienst als ausgefallen markieren</span>
                        @endif
                    </fieldset>
                @endforeach
                <div class="rt-day-review__actions">
                    <x-ui.buttons.button-basic type="button" wire:click="cancel">Abbrechen</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Zuordnung übernehmen</x-ui.buttons.button-basic>
                </div>
            </form>
        @else
            <div class="rt-day-review__actions">
                @if($summary['duties'])
                    <x-ui.buttons.button-basic type="button" x-ref="editButton" wire:click="edit">Zuordnung ändern</x-ui.buttons.button-basic>
                @endif
                <x-ui.buttons.button-basic type="button" mode="primary" wire:click="confirm" :disabled="(bool)$summary['issue']" wire:loading.attr="disabled">Tagesbeleg bestätigen</x-ui.buttons.button-basic>
            </div>
        @endif
    @endif
</section>
@assets
<style>
.rt-day-review{--day-rule:var(--rt-border,#cbd5df);--day-accent:#96243d;color:inherit;display:grid;grid-template-columns:minmax(0,1fr);gap:1rem;min-width:0;overflow-wrap:anywhere}
.rt-day-review__heading p,.rt-day-review__caption{margin:0 0 .2rem;color:inherit;opacity:.8;font-size:.875rem}
.rt-day-review h2,.rt-day-review h3,.rt-day-review legend{font-weight:650;margin:0;line-height:1.25}
.rt-day-review h2{font-size:1.4rem}.rt-day-review__totals{display:grid;grid-template-columns:minmax(8rem,.7fr) minmax(12rem,1.3fr);gap:1.5rem;align-items:start}
.rt-day-review__totals span,.rt-day-review__net{display:block}.rt-day-review__net{font-size:clamp(2rem,4vw,3.25rem);line-height:1.08;font-weight:650;letter-spacing:-.04em}
.rt-day-review dd,.rt-day-review__net{font-variant-numeric:tabular-nums}
.rt-day-review__bar{display:block;width:100%;height:.65rem;appearance:none;border:0;background:#68849c;border-radius:3px;overflow:hidden}
.rt-day-review__bar::-webkit-meter-bar{height:100%;border:0;background:#68849c}.rt-day-review__bar::-webkit-meter-optimum-value{background:var(--day-accent)}.rt-day-review__bar::-moz-meter-bar{background:var(--day-accent)}
.rt-day-review dl{margin:0}.rt-day-review__split div,.rt-day-review__times div{display:flex;justify-content:space-between;gap:.75rem;padding:.45rem 0;border-bottom:1px solid var(--day-rule)}
.rt-day-review dd{font-weight:600;margin:0;text-align:right}.rt-day-review__actions{display:flex;justify-content:flex-end;gap:.65rem;flex-wrap:wrap}
.rt-day-review__editor{display:grid;gap:.85rem}.rt-day-review__editor fieldset{padding:.7rem .9rem;border:1px solid var(--day-rule);border-radius:.5rem;min-width:0}
.rt-day-review__editor legend{padding:0 .25rem}.rt-day-review__inputs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-top:.5rem}
.rt-day-review__split dt{min-width:0}.rt-day-review__split dd{flex-shrink:0;white-space:nowrap}
.rt-day-review__performed{display:flex;align-items:center;gap:.5rem;width:fit-content;min-height:44px;cursor:pointer}
.rt-day-review [tabindex="-1"],.rt-day-review__actions button{scroll-margin-block:5rem}
.rt-day-review__inputs label{display:grid;gap:.2rem;font-size:.875rem}.rt-day-review input[type=datetime-local]{width:100%;min-width:0;min-height:2.75rem;background:transparent;border:1px solid var(--day-rule);border-radius:.375rem;padding:.45rem;color:inherit}
.rt-day-review__notice{border-inline-start:3px solid #ab7130;padding-inline-start:.7rem}
@media(max-width:640px){.rt-day-review__totals,.rt-day-review__inputs{grid-template-columns:1fr;gap:.75rem}.rt-day-review__actions>*{width:100%;min-height:44px}}
</style>
@endassets
