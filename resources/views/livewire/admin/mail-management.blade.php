<div class="space-y-5">
    <div class="overflow-hidden rounded-2xl border border-rt-ink bg-gradient-to-r from-rt-anthracite to-rt-ink shadow-sm dark:border-slate-700">
        <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-5">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">{{ __('app.messages_and_emails') }}</h1>
                <p class="mt-1 text-sm text-slate-300">{{ __('app.entries_in_mail_log', ['count' => $mails->total()]) }}</p>
            </div>
            @if(Auth::user()->isAdmin())
            <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-200 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-rt-red">Super Admin</p>
                <p class="font-semibold text-white">{{ config('mail.super_admin') ?: __('app.not_set') }}</p>
            </div>
            @endif
        </div>
    </div>

    @if (session()->has('message'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
            {{ session('error') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="hidden grid-cols-12 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-900/50 dark:text-slate-400 md:grid">
            <div class="col-span-1">
                <button wire:click="sortByField('id')" class="inline-flex items-center gap-1">
                    ID
                    @if ($sortBy === 'id')
                        <span><i class="fa fa-chevron-up {{ $sortDirection === 'asc' ? 'rotate-180' : '' }}" aria-hidden="true"></i></span>
                    @endif
                </button>
            </div>
            <div class="col-span-2">
                <button wire:click="sortByField('created_at')" class="inline-flex items-center gap-1">
                    {{ __('app.date') }}
                    @if ($sortBy === 'created_at')
                        <span><i class="fa fa-chevron-up {{ $sortDirection === 'asc' ? 'rotate-180' : '' }}" aria-hidden="true"></i></span>
                    @endif
                </button>
            </div>
            <div class="col-span-2">{{ __('app.delivery_type') }}</div>
            <div class="col-span-2">{{ __('app.recipients') }}</div>
            <div class="col-span-2">
                <button wire:click="sortByField('status')" class="inline-flex items-center gap-1">
                    {{ __('app.status') }}
                    @if ($sortBy === 'status')
                        <span><i class="fa fa-chevron-up {{ $sortDirection === 'asc' ? 'rotate-180' : '' }}" aria-hidden="true"></i></span>
                    @endif
                </button>
            </div>
            <div class="col-span-3">{{ __('app.actions') }}</div>
        </div>

        @forelse ($mails as $mail)
            @php
                $type = strtolower((string) ($mail->type ?? ''));
                $isMessageOnly = $type === 'message';
                $isMailOnly = $type === 'mail';
                $typeLabel = $isMessageOnly ? __('app.message') : ($isMailOnly ? __('app.email') : __('app.message_and_email'));
                $typeClass = $isMessageOnly
                    ? 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/30'
                    : ($isMailOnly ? 'bg-sky-100 text-sky-700 border-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:border-sky-500/30' : 'bg-violet-100 text-violet-700 border-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:border-violet-500/30');
                $uniqueRecipients = collect($mail->recipients ?? [])
                    ->filter(fn ($recipient) => is_array($recipient))
                    ->unique(fn ($recipient) => ((int) ($recipient['user_id'] ?? 0)) . '|' . strtolower((string) ($recipient['email'] ?? '')))
                    ->values();
                $linkRaw = (string) ($mail->content['link'] ?? '');
            @endphp

            <div x-data="{ open: false }" @click.away="open = false" class="border-t border-slate-100 first:border-t-0 dark:border-slate-700">
                <div class="px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-12 md:items-center">
                        <button @click="open = !open" class="text-left text-sm font-semibold text-slate-800 dark:text-slate-100 md:col-span-1">
                            #{{ $mail->id }}
                        </button>

                        <button @click="open = !open" class="text-left text-sm text-slate-600 dark:text-slate-400 md:col-span-2">
                            {{ $mail->created_at->format('d.m.Y H:i') }}
                        </button>

                        <button @click="open = !open" class="text-left md:col-span-2">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $typeClass }}">
                                {{ $typeLabel }}
                            </span>
                        </button>

                        <button @click="open = !open" class="text-left text-sm text-slate-600 dark:text-slate-400 md:col-span-2">
                            {{ __('app.x_recipients', ['count' => $uniqueRecipients->count()]) }}
                        </button>

                        <button @click="open = !open" class="text-left md:col-span-2">
                            @if ($mail->status)
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('app.sent') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ __('app.status_open') }}</span>
                            @endif
                        </button>

                        <div class="flex flex-wrap gap-2 md:col-span-3 md:justify-end">
                            <button
                                wire:click.stop="resendMail({{ $mail->id }})"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                            >
                                {{ __('app.resend') }}
                            </button>
                            @if(Auth::user()->isAdmin())
                            <button
                                wire:click.stop="sendMessageToSuperAdmin({{ $mail->id }})"
                                class="rounded-lg bg-rt-red px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/40"
                            >
                                SuperAdmin Test
                            </button>
                            @endif
                        </div>
                    </div>
                </div>

                <div x-show="open" x-collapse x-cloak class="border-t border-slate-100 bg-slate-50 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/50">
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.subject') }}</p>
                            <p class="mt-1 text-sm font-medium text-slate-800 dark:text-slate-200">{{ $mail->content['subject'] ?? '-' }}</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.delivery_type') }}</p>
                            <p class="mt-1 text-sm font-medium text-slate-800 dark:text-slate-200">{{ $typeLabel }}</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Link</p>
                            @if ($linkRaw !== '')
                                @if (str_contains($linkRaw, '<'))
                                    <div class="prose prose-sm mt-1 max-w-none text-slate-700 dark:prose-invert dark:text-slate-300">{!! $linkRaw !!}</div>
                                @elseif (filter_var($linkRaw, FILTER_VALIDATE_URL))
                                    <a href="{{ $linkRaw }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block truncate text-sm text-rt-red underline hover:text-rt-red-dark">{{ $linkRaw }}</a>
                                @else
                                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ $linkRaw }}</p>
                                @endif
                            @else
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_link') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.message') }}</p>
                        <div class="prose prose-sm mt-2 max-w-none text-slate-700 dark:prose-invert dark:text-slate-300">
                            {!! (string) ($mail->content['body'] ?? '') !!}
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                        <h4 class="text-sm font-semibold text-slate-800 dark:text-white">{{ __('app.recipients') }}</h4>
                        <ul class="mt-3 max-h-44 space-y-2 overflow-y-auto">
                            @foreach ($uniqueRecipients as $recipient)
                                @php
                                    $recipientUserId = (int) ($recipient['user_id'] ?? 0);
                                    $recipientUser = $recipientUserId > 0 ? ($recipientUsers[$recipientUserId] ?? null) : null;
                                @endphp
                                <li class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900/40">
                                    <div class="min-w-0 pr-3">
                                        @if ($recipientUser)
                                            <x-user.public-info :user="$recipientUser" :size="8" />
                                        @else
                                            <span class="truncate text-slate-700 dark:text-slate-300">{{ $recipient['email'] ?? __('app.unknown') }}</span>
                                        @endif
                                    </div>
                                    @if (!empty($recipient['status']))
                                        <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ __('app.sent') }}</span>
                                    @else
                                        <span class="text-xs font-semibold text-rose-600 dark:text-rose-400">{{ __('app.not_sent') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @empty
            <div class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_mails') }}</div>
        @endforelse
    </div>

    <div>
        {{ $mails->links() }}
    </div>
</div>
