@foreach($columnsMeta as $column)
<div class="rt-table-cell {{ $column['key'] === 'name' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
    @if($column['key'] === 'name')
        <div class="min-w-0">@if($item->user)<x-user.person-anchor-preview :user="$item->user" :show-presence="false" :show-email="false" :size="8" />@else<span>Mitarbeiter nicht verfügbar</span>@endif
            @if($item->exception_review)<details class="rt-staffing-reasons rt-staffing-reasons--warning"><summary><i class="far fa-shield-exclamation" aria-hidden="true"></i> Ausnahme dokumentiert</summary><p>{{ $item->exception_review->data['reason'] ?? '' }}</p><p>{{ $item->exception_review->actor?->name ?? 'Ehemalige Verwaltung' }} · {{ $item->exception_review->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} · Revision {{ $item->exception_review->data['plan_revision'] ?? '–' }}</p><p>Historischer Prüfstand; Änderungen werden erneut geprüft.</p></details>@endif
            @if(count($item->planning_issues))<details class="rt-staffing-reasons rt-staffing-reasons--warning"><summary>{{ count($item->planning_issues) }} Planungshinweis(e)</summary><ul>@foreach($item->planning_issues as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul><p>Eine dokumentierte Ausnahme hebt diese Hinweise nicht auf.</p>@if($item->exception_review)<p>Ist der Prüfstand inzwischen verändert, entfernen Sie die Zuweisung und prüfen Sie sie bei erneuter Auswahl vollständig neu.</p>@endif</details>@endif
        </div>
    @elseif($column['key'] === 'response')
        <div>
            <x-operations.status :value="$item->status->value" :label="$item->status->label()" />
            @if($item->plan_revision > 0 && $item->plan_revision === $item->shift->published_revision)
                <span class="rt-table-meta">Revision {{ $item->plan_revision }}@if($item->responded_at) · {{ $item->responded_at->setTimezone($displayTimezone ?? config('operations.display_timezone'))->format('d.m. H:i') }}@endif</span>
                <span class="rt-table-meta"><i class="far {{ $item->plan_opened_at ? 'fa-eye' : 'fa-envelope' }}" aria-hidden="true"></i> {{ $item->plan_opened_at ? 'Geöffnet am '.$item->plan_opened_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i') : 'Noch nicht geöffnet' }}</span>
            @else<span class="rt-table-meta">Aktueller Stand noch nicht veröffentlicht</span>@endif
        </div>
    @else
        @if($item->status->blocksAvailability())<x-ui.buttons.button-basic type="button" wire:click="removeAssignment({{ $item->id }})" wire:confirm="Zuweisung wirklich entfernen?" wire:loading.attr="disabled" :aria-label="$item->user?->name.' aus der Schicht entfernen'" title="Zuweisung entfernen"><i class="far fa-user-minus" aria-hidden="true"></i></x-ui.buttons.button-basic>@endif
    @endif
</div>
@endforeach
