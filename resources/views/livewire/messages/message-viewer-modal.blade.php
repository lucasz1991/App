<div>
    <x-modal id="message-viewer-modal" wire:model="isOpen" maxWidth="4xl">
        @if ($message)
            @php
                $isAdminSender = optional($message->sender)->role === 'admin';
                $senderName = $isAdminSender
                    ? config('app.name')
                    : ($message->sender?->name ?? __('app.unknown'));
                $senderAvatar = $isAdminSender
                    ? asset('rt-brand/rt-logo.svg')
                    : ($message->sender?->profile_photo_url ?? asset('rt-brand/rt-logo.svg'));
                $createdAbsolute = $message->created_at?->format('d.m.Y H:i');
                $createdRelative = $message->created_at?->diffForHumans();
            @endphp

            <article
                class="flex max-h-[calc(100dvh-3rem)] min-h-0 flex-col overflow-hidden rounded-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="message-viewer-title"
                data-testid="message-viewer-modal"
                wire:key="message-viewer-{{ $message->id }}"
            >
                <header class="relative shrink-0 overflow-hidden border-b border-rt-border/70 bg-rt-surface-muted px-5 py-5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted sm:px-7">
                    <div class="absolute inset-y-0 left-0 w-1 bg-rt-red" aria-hidden="true"></div>

                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3.5">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-rt-surface p-1 shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
                                <img
                                    src="{{ $senderAvatar }}"
                                    class="h-full w-full rounded-lg object-cover"
                                    alt="{{ $senderName }}"
                                >
                            </span>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                        {{ $senderName }}
                                    </p>
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                        {{ __('app.read') }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted" title="{{ $createdAbsolute }}">
                                    {{ __('app.from') }} {{ $senderName }}
                                    <span class="px-1" aria-hidden="true">&middot;</span>
                                    {{ $createdRelative }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            wire:click="close"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-rt-muted transition-all duration-200 hover:bg-rt-nav-hover hover:text-rt-text active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-text"
                            aria-label="{{ __('app.close') }}"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                            </svg>
                        </button>
                    </div>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto bg-rt-surface px-5 py-6 dark:bg-rt-dark-surface sm:px-7 sm:py-7">
                    <section aria-labelledby="message-viewer-title">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rt-soft dark:text-rt-dark-soft">
                            {{ __('app.subject') }}
                        </p>
                        <h2 id="message-viewer-title" class="mt-2 max-w-3xl text-balance text-xl font-semibold leading-tight tracking-tight text-rt-text dark:text-rt-dark-text sm:text-2xl">
                            {{ $message->subject }}
                        </h2>

                        <div class="mt-6 max-w-[68ch] whitespace-pre-wrap break-words text-[0.9375rem] leading-7 text-rt-text dark:text-rt-dark-text">{{ $message->message }}</div>
                    </section>

                    @if ($message->files?->count())
                        <section class="mt-8 border-t border-rt-border/70 pt-6 dark:border-rt-dark-border/70" aria-labelledby="message-attachments-title">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 id="message-attachments-title" class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                    <i class="far fa-paperclip text-rt-red" aria-hidden="true"></i>
                                    {{ __('app.attachments') }}
                                </h3>
                                <span class="text-xs font-medium tabular-nums text-rt-muted dark:text-rt-dark-muted">
                                    {{ $message->files->count() }}
                                </span>
                            </div>

                            <ul class="mt-3 divide-y divide-rt-border/70 overflow-hidden rounded-xl bg-rt-surface-muted ring-1 ring-rt-border/70 dark:divide-rt-dark-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70">
                                @foreach ($message->files as $file)
                                    @php $fileUrl = $file->getEphemeralPublicUrl(10); @endphp
                                    <li class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-surface text-rt-red shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
                                                <i class="far fa-file" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                                    {{ $file->name_with_extension }}
                                                </p>
                                                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                                                    {{ $file->getMimeTypeForHumans() }}
                                                    <span class="px-1" aria-hidden="true">&middot;</span>
                                                    {{ $file->size_formatted }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 flex-wrap items-center gap-2 pl-[3.25rem] sm:pl-0">
                                            @if ($fileUrl)
                                                <a
                                                    href="{{ $fileUrl }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-rt-muted transition-all duration-200 hover:bg-rt-nav-hover hover:text-rt-text active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-text"
                                                >
                                                    <i class="far fa-external-link-alt" aria-hidden="true"></i>
                                                    {{ __('app.open') }}
                                                </a>
                                            @endif

                                            <button
                                                type="button"
                                                wire:click="downloadFile({{ $file->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="downloadFile({{ $file->id }})"
                                                class="inline-flex items-center gap-2 rounded-lg bg-rt-red px-3 py-2 text-xs font-semibold text-white shadow-rt-xs transition-all duration-200 hover:bg-rt-red-dark active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:focus-visible:ring-offset-rt-dark-surface"
                                            >
                                                <i class="far fa-download" aria-hidden="true"></i>
                                                {{ __('app.download') }}
                                            </button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>

                <footer class="flex shrink-0 justify-end border-t border-rt-border/70 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted sm:px-7">
                    <button
                        type="button"
                        wire:click="close"
                        class="inline-flex items-center justify-center rounded-lg border border-rt-border bg-rt-surface px-4 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition-all duration-200 hover:bg-rt-nav-hover active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-nav-hover"
                    >
                        {{ __('app.close') }}
                    </button>
                </footer>
            </article>
        @endif
    </x-modal>
</div>
