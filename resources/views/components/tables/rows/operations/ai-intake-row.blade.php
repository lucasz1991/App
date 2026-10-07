@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'title' ? 'rt-table-cell--primary' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}" wire:key="ai-intake-{{ $item->id }}-{{ $column['key'] }}">
        @switch($column['key'])
            @case('title')<div class="rt-table-record"><span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-inbox"></i></span><div class="rt-table-record__body"><span class="rt-table-record__eyebrow">AI-{{ str_pad((string)$item->id,6,'0',STR_PAD_LEFT) }}</span><x-ui.buttons.button-basic mode="link" type="button" class="rt-table-record__title" wire:click="select({{ $item->id }})">{{ $item->title }}</x-ui.buttons.button-basic><span class="rt-table-record__meta">{{ $item->summary }}</span></div></div>@break
            @case('customer')<span class="rt-table-value">{{ $item->customer?->company_name ?? 'Zuordnung prüfen' }}</span>@break
            @case('status')<x-operations.status :value="$item->status" :label="\App\Livewire\Operations\AiIntakeInbox::LABELS[$item->status] ?? 'Prüfung nötig'" />@break
            @case('created')<span class="rt-table-meta">{{ $item->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>@break
        @endswitch
    </div>
@endforeach
