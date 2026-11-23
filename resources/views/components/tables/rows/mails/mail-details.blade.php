@php
    $content = is_array($item->content) ? $item->content : [];
    $recipients = collect($item->recipients ?? [])->filter(fn ($recipient) => is_array($recipient))->unique(fn ($recipient) => ((int) ($recipient['user_id'] ?? 0)).'|'.strtolower((string) ($recipient['email'] ?? '')));
@endphp

<div class="border-t border-rt-border bg-rt-surface-muted px-4 py-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.surface.card padding="p-4"><p class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.subject') }}</p><p class="mt-1 text-sm font-medium">{{ $content['subject'] ?? '-' }}</p></x-ui.surface.card>
        <x-ui.surface.card padding="p-4"><p class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">Link</p><p class="mt-1 break-words text-sm">{{ strip_tags((string) ($content['link'] ?? __('app.no_link'))) }}</p></x-ui.surface.card>
        <x-ui.surface.card padding="p-4"><p class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.recipients') }}</p><p class="mt-1 text-sm">{{ $recipients->pluck('email')->filter()->implode(', ') ?: __('app.no_entries_found') }}</p></x-ui.surface.card>
    </div>
    <x-ui.surface.card padding="p-4" class="mt-4"><p class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.message') }}</p><div class="prose prose-sm mt-2 max-w-none text-rt-text dark:prose-invert dark:text-white">{!! (string) ($content['body'] ?? '') !!}</div></x-ui.surface.card>
</div>
