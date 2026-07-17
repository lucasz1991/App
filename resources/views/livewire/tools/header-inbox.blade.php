<div class="relative" wire:poll.60s="loadInbox">
    <x-dropdown align="right" width="w-96">
        {{-- Trigger --}}
        <x-slot name="trigger">
            <button type="button"
                    title="{{ __('app.messages') }}"
                    aria-haspopup="true"
                    class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                <i class="far fa-envelope text-base" aria-hidden="true"></i>

                @if ($unreadMessagesCount >= 1)
                    <span class="absolute -right-1.5 -top-1.5 rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
                        {{ $unreadMessagesCount }}
                    </span>
                @endif
            </button>
        </x-slot>

        {{-- Content --}}
        <x-slot name="content">
            <div class="max-w-[calc(100vw-2rem)] divide-y divide-slate-200 text-[0.8125rem]/5 text-slate-900 dark:divide-slate-700 dark:text-slate-200">
                @forelse ($receivedMessages as $message)
                    @php
                        $isAdminSender = optional($message->sender)->role === 'admin';
                        $senderName    = $isAdminSender ? config('app.name') : ($message->sender->name ?? __('app.unknown'));
                        $senderAvatar  = $isAdminSender
                            ? asset('rt-brand/rt-logo.svg')
                            : ($message->sender?->profile_photo_url ?? asset('rt-brand/rt-logo.svg'));
                        $isUnread      = (int) $message->status === 1;
                    @endphp

                    <button
                        type="button"
                        class="flex w-full items-center gap-3 p-3 text-left hover:bg-slate-50 dark:hover:bg-slate-700 {{ $isUnread ? 'bg-blue-50 dark:bg-slate-700/60' : '' }}"
                        x-on:click="$wire.showMessage({{ $message->id }})"
                    >
                        <img src="{{ $senderAvatar }}" class="h-8 w-8 rounded-full object-cover" alt="">
                        <div class="min-w-0 flex-auto">
                            <div class="flex items-center gap-2">
                                <div class="truncate font-medium {{ $isUnread ? 'font-semibold' : '' }}">{{ $senderName }}</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400" title="{{ $message->created_at->format('d.m.Y H:i') }}">
                                    {{ $message->created_at->diffForHumans() }}
                                </div>
                            </div>
                            <div class="truncate text-slate-900 dark:text-slate-100 {{ $isUnread ? 'font-medium' : '' }}">{{ $message->subject }}</div>
                            <div class="mt-0.5 flex items-center gap-2 text-slate-700 dark:text-slate-300">
                                @if ($message->files_count > 0)
                                    <i class="far fa-paperclip shrink-0 text-gray-500 dark:text-slate-400" aria-hidden="true"></i>
                                @endif
                                <span class="truncate">{{ \Illuminate\Support\Str::limit(strip_tags($message->message), 60) }}</span>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="p-4 text-center text-slate-700 dark:text-slate-400">{{ __('app.no_messages') }}</div>
                @endforelse

                <div class="p-3">
                    <a href="{{ route('messages') }}"
                       class="block rounded-md px-4 py-2 text-center font-medium ring-1 ring-slate-700/10 hover:bg-slate-50 dark:ring-slate-600 dark:hover:bg-slate-700">
                        {{ __('app.view_all_messages') }}
                    </a>
                </div>
            </div>
        </x-slot>
    </x-dropdown>

    {{-- Anzeige-Modal --}}
    <x-ui.messages.message-show-modal
        model="showMessageModal"
        :message="$selectedMessage"
    />
</div>
