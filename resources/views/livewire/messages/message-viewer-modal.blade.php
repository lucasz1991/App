<div data-testid="message-viewer-host">
    <x-dialog-modal id="message-viewer-modal" wire:model="isOpen" max-width="4xl">
        <x-slot:title>
            <span id="message-viewer-title">{{ $message?->subject ?? __('app.message') }}</span>
        </x-slot:title>

        <x-slot:content>
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
                    $safeActionUrl = is_string($message->action_url) && str_starts_with($message->action_url, '/')
                        ? $message->action_url
                        : null;
                @endphp

                <article
                    class="min-w-0"
                    data-testid="message-viewer-modal"
                    wire:key="message-viewer-{{ $message->id }}"
                >
                    <header class="flex min-w-0 items-center gap-3.5 border-b border-rt-border/70 pb-5 dark:border-rt-dark-border/70" data-message-viewer-sender>
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
                    </header>

                    <section class="pt-6" aria-labelledby="message-viewer-title">
                        <div class="max-w-[68ch] whitespace-pre-wrap break-words text-[0.9375rem] leading-7 text-rt-text dark:text-rt-dark-text">{{ $message->message }}</div>

                        @if($safeActionUrl)
                            <a
                                href="{{ $safeActionUrl }}"
                                wire:navigate
                                class="mt-6 inline-flex items-center gap-2 rounded-lg bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40"
                            >
                                <i class="far fa-arrow-right" aria-hidden="true"></i>
                                {{ $message->action_label ?: __('app.open') }}
                            </a>
                        @endif
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

                                            <x-ui.buttons.button-basic mode="primary"
                                                type="button"
                                                wire:click="downloadFile({{ $file->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="downloadFile({{ $file->id }})"
                                            >
                                                <i class="far fa-download" aria-hidden="true"></i>
                                                {{ __('app.download') }}
                                            </x-ui.buttons.button-basic>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </article>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <x-ui.buttons.button-basic mode="secondary"
                type="button"
                wire:click="close"
            >
                {{ __('app.close') }}
            </x-ui.buttons.button-basic>
        </x-slot:footer>
    </x-dialog-modal>
</div>
