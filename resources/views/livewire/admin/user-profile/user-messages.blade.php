<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('app.messages') }}</h3>
        <x-ui.buttons.button-basic wire:click="compose" :size="'sm'" :can="'users.messages.create'">
            <i class="far fa-paper-plane mr-2"></i>
            {{ __('app.compose_message') }}
        </x-ui.buttons.button-basic>
    </div>

    <div class="rounded-xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
            @forelse ($messages as $message)
                <button type="button"
                        wire:click="showMessage({{ $message->id }})"
                        class="flex w-full items-center justify-between gap-4 px-5 py-3 text-left transition-colors duration-300 ease-rt-spring first:rounded-t-xl last:rounded-b-xl hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted/60">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-900 dark:text-white">
                            @if ((int) $message->status === 1)
                                <span class="mr-1 inline-block h-2 w-2 rounded-full bg-rt-red align-middle" title="{{ __('app.unread') }}"></span>
                            @endif
                            {{ $message->subject }}
                        </p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                            {{ __('app.from') }}: {{ $message->sender?->name ?? __('app.unknown') }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs text-slate-400">{{ $message->created_at?->format('d.m.Y H:i') }}</span>
                </button>
            @empty
                <p class="px-5 py-6 text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_messages') }}</p>
            @endforelse
        </div>
    </div>

    <div>
        {{ $messages->links() }}
    </div>

    {{-- Detail-Modal --}}
    <x-dialog-modal wire:model="showMessageModal" maxWidth="2xl">
        <x-slot name="title">
            {{ $selectedMessage?->subject ?? __('app.message') }}
        </x-slot>

        <x-slot name="content">
            @if ($selectedMessage)
                <div class="space-y-3">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ __('app.from') }}: {{ $selectedMessage->sender?->name ?? __('app.unknown') }}
                        &middot; {{ $selectedMessage->created_at?->format('d.m.Y H:i') }}
                    </p>
                    <div class="prose prose-sm max-w-none text-slate-700 dark:prose-invert dark:text-slate-300">
                        {!! nl2br(e($selectedMessage->message)) !!}
                    </div>

                    @if ($selectedMessage->files?->count())
                        <div>
                            <p class="mb-2 text-sm font-medium text-slate-900 dark:text-white">{{ __('app.attachments') }}</p>
                            <ul class="space-y-1">
                                @foreach ($selectedMessage->files as $file)
                                    <li class="text-sm text-slate-600 dark:text-slate-300">
                                        <i class="far fa-paperclip mr-1"></i>{{ $file->name }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif
        </x-slot>

        <x-slot name="footer">
            @can('users.messages.delete')
                @if ($selectedMessage)
                    <x-ui.buttons.button-basic :mode="'danger'" wire:click="deleteMessage({{ $selectedMessage->id }})"
                        wire:confirm="{{ __('app.note_delete_confirm') }}" class="mr-2">
                        <i class="far fa-trash-alt mr-2"></i>
                        {{ __('app.delete') }}
                    </x-ui.buttons.button-basic>
                @endif
            @endcan
            <x-ui.buttons.button-basic wire:click="closeMessage">
                {{ __('app.close') }}
            </x-ui.buttons.button-basic>
        </x-slot>
    </x-dialog-modal>
</div>
