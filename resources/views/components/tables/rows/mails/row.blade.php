@php
    $hc = fn ($i) => $hideClass($columnsMeta[$i]['hideOn'] ?? 'none');
    $type = strtolower((string) ($item->type ?? ''));
    $typeLabel = $type === 'message' ? __('app.message') : ($type === 'mail' ? __('app.email') : __('app.message_and_email'));
    $typeColor = $type === 'message' ? 'yellow' : ($type === 'mail' ? 'blue' : 'purple');
    $recipientCount = collect($item->recipients ?? [])->filter(fn ($recipient) => is_array($recipient))->unique(fn ($recipient) => ((int) ($recipient['user_id'] ?? 0)).'|'.strtolower((string) ($recipient['email'] ?? '')))->count();
@endphp

<div data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}" class="truncate px-2 py-2 text-left font-semibold text-rt-text dark:text-white {{ $hc(0) }}">#{{ $item->id }}</div>
<div data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}" class="truncate px-2 py-2 text-left text-rt-muted dark:text-rt-dark-muted {{ $hc(1) }}">{{ $item->created_at->format('d.m.Y H:i') }}</div>
<div data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}" class="px-2 py-2 text-left {{ $hc(2) }}"><x-ui.badge :color="$typeColor">{{ $typeLabel }}</x-ui.badge></div>
<div data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}" class="truncate px-2 py-2 text-left text-rt-muted dark:text-rt-dark-muted {{ $hc(3) }}">{{ __('app.x_recipients', ['count' => $recipientCount]) }}</div>
<div data-rt-table-label="{{ $columnsMeta[4]['label'] ?? '' }}" class="px-2 py-2 text-left {{ $hc(4) }}">
    <x-ui.badge :color="$item->status ? 'green' : 'red'">{{ $item->status ? __('app.sent') : __('app.status_open') }}</x-ui.badge>
</div>
