@foreach($columnsMeta as $column)<div class="min-w-0 px-2 py-2 text-sm">
@if($column['key']==='section')<p class="font-semibold">{{ \App\Services\Operations\DutyActivityService::KINDS[$item->kind] ?? $item->kind }}</p><p class="ops-muted">{{ $item->label }}</p>
@elseif($column['key']==='time'){{ \Carbon\CarbonImmutable::parse($item->starts_at)->setTimezone($item->timezone)->format('d.m. H:i') }} – {{ \Carbon\CarbonImmutable::parse($item->ends_at)->setTimezone($item->timezone)->format('d.m. H:i') }}
@elseif(!$this->employeeMode)<div class="ops-actions"><x-ui.buttons.button-basic wire:click="editSection({{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="removeSection({{ $item->id }},{{ $item->plan_revision }})" wire:confirm="Abschnitt entfernen?" wire:loading.attr="disabled">Entfernen</x-ui.buttons.button-basic></div>@endif
</div>@endforeach
