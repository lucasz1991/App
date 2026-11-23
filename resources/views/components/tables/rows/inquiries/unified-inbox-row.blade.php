@php
    $key = $item->id;
    $title = $item->title;
    $action = match($item->bucket) { 'waiting' => 'Antwort / Zusage prüfen', 'ready' => 'Weiterbearbeitung öffnen', 'done' => 'Verlauf ansehen', default => 'Angaben prüfen' };
    $state = match($item->bucket) { 'waiting' => 'Rückmeldung offen', 'ready' => 'Bereit', 'done' => 'Erledigt', default => 'Zu prüfen' };
    if ($item->kind === 'ai') {
        $state = \App\Livewire\Operations\AiIntakeInbox::LABELS[$item->status] ?? $state;
        $action = in_array($item->status, ['received', 'analyzing'], true) ? 'Verarbeitung ansehen' : $action;
    }
    $source = ['email'=>'E-Mail','portal'=>'Portal','phone'=>'Telefon','manual'=>'Manuell','ai'=>'AI-Annahme','import'=>'Import'][$item->channel] ?? $item->channel;
@endphp
@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'title' ? 'rt-table-cell--primary' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}" wire:key="unified-{{ $key }}-{{ $column['key'] }}">
        @switch($column['key'])
            @case('title')
                <div class="rt-table-record__body"><x-ui.buttons.button-basic type="button" mode="link" class="rt-table-record__title" wire:click="select('{{ $item->kind }}', {{ $item->record_id }})">{{ $title }}</x-ui.buttons.button-basic><span class="rt-table-record__meta">{{ $item->customer_name ?: 'Kunde noch zuordnen' }}</span></div>
                @break
            @case('channel')
                <span class="rt-table-meta">{{ $source }}
                    @if($item->kind === 'ai')
                        · AI-Annahme
                    @endif
                </span>
                @break
            @case('status')<x-operations.status :value="$item->status" :label="$state" />@break
            @case('next')<div><x-ui.buttons.button-basic type="button" mode="link" wire:click="select('{{ $item->kind }}', {{ $item->record_id }})" :aria-label="$action.': '.$title">{{ $action }} <i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic><span class="rt-table-meta">{{ \Illuminate\Support\Carbon::parse($item->updated_at)->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span></div>@break
        @endswitch
    </div>
@endforeach
