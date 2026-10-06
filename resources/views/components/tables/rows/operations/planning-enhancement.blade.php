@foreach($columnsMeta as $column)
    @php($key=$column['key'])
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @if($key==='bundle')<span class="font-semibold">{{ $item->name }}</span><p class="ops-muted">{{ $item->role_name }} · v{{ $item->version }}</p>
        @elseif($key==='requirements')@foreach($item->requirements as $requirement)<p class="text-xs {{ $requirement['mandatory']?'':'ops-muted' }}">{{ $requirement['name'] }}{{ $requirement['mandatory']?'':' · Entwicklungsziel' }}</p>@endforeach
        @elseif($key==='members')<span class="tabular-nums">{{ count($item->user_ids) }}</span>
        @elseif($key==='cycle')<p class="tabular-nums">{{ $item->cycle_days }} Tage · {{ $item->anchor }}</p>
        @elseif($key==='chain')@php($a=\App\Models\Shift::find($item->predecessor_id))@php($b=\App\Models\Shift::find($item->successor_id))<p>{{ $a?->title ?? '—' }} → {{ $b?->title ?? '—' }}</p>
        @elseif($key==='handover')<p>{{ $item->handover_location }} · {{ $item->transfer_minutes }} min</p>@if($item->same_employee)<p class="ops-muted">Gleicher Mitarbeiter</p>@endif
        @elseif($key==='resources')<p>{{ $item->train_code ?: '—' }} / {{ $item->vehicle_code ?: '—' }}</p>
        @elseif($key==='actions')<div class="ops-actions">@if($item instanceof \App\Models\QualificationBundle)<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('bundle',{{ $item->id }})">Neue Version</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="edit('bundle-coverage',{{ $item->id }})">Abdeckung</x-ui.buttons.button-basic>@if($item->status==='draft')<x-ui.buttons.button-basic type="button" wire:click="approve({{ $item->id }},{{ $item->revision }})">Freigeben</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic type="button" wire:click="edit('attach',{{ $item->id }})">Zuordnen</x-ui.buttons.button-basic>@endif
            @elseif($item instanceof \App\Models\PlanningTeam)<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('team',{{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>@if($item->is_active)<x-ui.buttons.button-basic type="button" wire:click="edit('bulk',{{ $item->id }})">Einteilen</x-ui.buttons.button-basic>@endif
            @elseif($item instanceof \App\Models\RotationCycle)<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('rotation',{{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="edit('rotation-preview',{{ $item->id }})">Vorschau</x-ui.buttons.button-basic>
            @elseif(isset($item->target_fte))<x-operations.status :value="$item->status" />@if($item->status==='draft')<x-ui.buttons.button-basic type="button" wire:click="approve({{ $item->id }},{{ $item->revision }})">Freigeben</x-ui.buttons.button-basic>@endif@endif</div>
        @elseif($key==='variant_actions')<div class="ops-actions">@if($item->status==='draft')<x-ui.buttons.button-basic type="button" wire:click="inspectVariant({{ $item->id }})">Prüfen</x-ui.buttons.button-basic>@elseif($item->status==='approved')<x-ui.buttons.button-basic type="button" wire:click="applyVariant({{ $item->id }},{{ $item->revision }})" wire:confirm="Freigegebene Variante als Dienstentwurf übernehmen?">Übernehmen</x-ui.buttons.button-basic>@endif</div>
        @elseif($key==='preview_title'){{ $item->title ?? $item->name ?? '—' }}
        @elseif($key==='preview_people'){{ $item->name ?? $item->people ?? count($item->user_ids ?? []) }}
        @elseif($key==='preview_issues')@php($messages=collect($item->issues ?? [])->flatMap(fn($value)=>is_string($value)?[$value]:(isset($value['message'])?[$value['message']]:collect($value)->pluck('message')->all()))->merge($item->schedule_issues ?? [])->filter(fn($v)=>is_string($v))->all())@if($messages===[])<span class="text-green-700 dark:text-green-300">Geprüft</span>@else @foreach($messages as $message)<p class="text-xs text-amber-700 dark:text-amber-300">{{ $message }}</p>@endforeach @endif
        @elseif($key==='proposals')@foreach($item->proposals as $proposal)<p class="font-medium">{{ $proposal['name'] }}</p><p class="ops-muted">{{ implode(' · ',$proposal['reasons']) }}</p>@endforeach
        @elseif($key==='status')<x-operations.status :value="$item->status" />
        @else<span class="mr-1 text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span><span class="break-words {{ $loop->first?'font-semibold':'text-rt-muted dark:text-rt-dark-muted' }} tabular-nums">{{ data_get($item,$key) ?? '—' }}</span>@endif
    </div>
@endforeach
