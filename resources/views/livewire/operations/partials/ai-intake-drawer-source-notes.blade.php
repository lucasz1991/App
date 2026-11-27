{{-- Formularbearbeitung, Reiter „Details“: Zusammenfassung, offene Angaben und Prüfhinweise des Eingangs. --}}
@if($selected->summary)<p>{{ $selected->summary }}</p>@endif
@if($selected->missing_fields)<ul>@foreach($selected->missing_fields as $field)@php $fieldKey = is_array($field) ? ($field['field'] ?? '') : $field; @endphp<li>{{ is_array($field) && isset($field['question']) ? $field['question'] : ($fieldLabels[$fieldKey] ?? 'Angabe prüfen') }}</li>@endforeach</ul>@endif
@if($selected->error_code)<p class="ops-muted" role="status">Die Verarbeitung benötigt eine Prüfung. Quellen und erkannte Angaben bleiben erhalten.</p>@endif
@if($selected->proposals->contains(fn ($record) => $record->source_revision !== $selected->source_revision || $record->status === 'stale'))<p class="ops-muted">Ein neuerer Eingang liegt vor. Frühere Angaben erst nach Prüfung der aktuellen Quelle übernehmen.</p>@endif
@if(!$enabled && $selected->source_type !== 'email')<p class="ops-muted">Die automatische Auswertung ist aus. Eingang und Anlagen bleiben erhalten.</p>@endif
@include('livewire.operations.partials.ai-intake-context-hints', ['showCandidateHints' => false])
