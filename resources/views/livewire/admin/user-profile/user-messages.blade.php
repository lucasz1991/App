<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('app.messages') }}</h3>
        <x-ui.buttons.button-basic wire:click="compose" :size="'sm'" :can="'users.messages.create'">
            <i class="far fa-paper-plane mr-2"></i>
            {{ __('app.compose_message') }}
        </x-ui.buttons.button-basic>
    </div>

    <x-tables.table
        :columns="[['label' => __('app.subject'), 'key' => 'subject', 'width' => 'minmax(0,1fr)'], ['label' => __('app.date'), 'key' => 'created_at', 'width' => '9rem']]"
        :items="$messages"
        :label="__('app.messages')"
        table-key="user-messages"
        detail-action="showMessage"
        :detail-on-click="true"
        row-view="components.tables.rows.messages.user-message-row"
        :empty="__('app.no_messages')"
        :flush-top="true"
    />

    <div>
        <x-tables.pagination :paginator="$messages" label="Nachrichtenseiten" />
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
                    <x-ui.buttons.button-basic
                        :mode="'danger'"
                        confirm-method="deleteMessage"
                        :confirm-arguments="[(int) $selectedMessage->id]"
                        :confirm-title="__('app.delete')"
                        :confirm-message="__('app.note_delete_confirm')"
                        :confirm-label="__('app.delete')"
                        class="mr-2"
                    >
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
