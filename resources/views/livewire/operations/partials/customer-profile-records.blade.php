<div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
    @forelse($records as $record)
        <div class="flex min-w-0 items-start justify-between gap-3 py-3" wire:key="profile-record-{{ $view }}-{{ $record['number'] ?? 'task' }}-{{ $record['id'] }}">
            <div class="min-w-0 flex-1">
                @if(!empty($record['number']))<p class="mb-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $record['number'] }}</p>@endif
                @if(!empty($record['detail_url']))<a href="{{ $record['detail_url'] }}" class="block break-words text-sm font-semibold text-rt-text hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-text">{{ $record['title'] }}</a>@else<p class="break-words text-sm font-semibold">{{ $record['title'] }}</p>@endif
                @if(!empty($record['time_label']) || !empty($record['due_label']))<p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $record['time_label'] ?? $record['due_label'] }}</p>@endif
                @if(!empty($record['location']))<p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $record['location'] }}</p>@endif
                @if(!empty($record['assignee']))<p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $record['assignee'] }}</p>@endif
            </div>
            @if(!empty($record['status_label']))<x-ui.badge color="slate">{{ $record['status_label'] }}</x-ui.badge>@endif
        </div>
    @empty
        <p class="py-5 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $empty }}</p>
    @endforelse
</div>
