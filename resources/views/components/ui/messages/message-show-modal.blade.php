@props([
    'model'    => null,   // Livewire-Property-Name, z. B. "showMessageModal"
    'message'  => null,
    'teamName' => null,
    'teamLogo' => null,
])

@php
    $teamName ??= config('app.name');
    $teamLogo ??= asset('rt-brand/rt-logo.svg');

    $isAdminSender = optional($message?->sender)->role === 'admin';
    $senderName    = $isAdminSender ? $teamName : ($message?->sender?->name ?? __('app.unknown'));
    $senderAvatar  = $isAdminSender
        ? $teamLogo
        : ($message?->sender?->profile_photo_url ?? $teamLogo);
    $createdAbs    = $message?->created_at?->format('d.m.Y H:i');
    $createdRel    = $message?->created_at?->diffForHumans();
    $subject       = $message?->subject ?? __('app.message');
@endphp

<x-dialog-modal :wire:model="$model" maxWidth="4xl">
    {{-- Titel: Absender + Zeitpunkt --}}
    <x-slot name="title">
        @if ($message)
            <div class="flex items-center gap-3">
                <img src="{{ $senderAvatar }}" class="h-8 w-8 rounded-full object-cover" alt="">
                <div class="min-w-0">
                    <div class="truncate font-medium leading-tight text-slate-900 dark:text-white">{{ $senderName }}</div>
                    <div class="truncate text-xs text-slate-500 dark:text-slate-400" title="{{ $createdAbs }}">
                        {{ $createdRel }}
                    </div>
                </div>
            </div>
        @else
            <span class="font-semibold text-slate-900 dark:text-white">{{ __('app.message') }}</span>
        @endif
    </x-slot>

    {{-- Inhalt --}}
    <x-slot name="content">
        @if ($message)
            <div class="mb-4 mt-6 space-y-6 rounded-xl border border-slate-200 bg-slate-50 p-6 dark:border-slate-700 dark:bg-slate-800">
                {{-- Betreff --}}
                <h3 class="mb-1 border-b border-slate-200 pb-2 text-xl font-semibold text-slate-900 dark:border-slate-700 dark:text-white">
                    {{ $subject }}
                </h3>

                {{-- Nachrichtentext --}}
                <div class="prose prose-sm max-w-none text-slate-800 dark:prose-invert dark:text-slate-200">
                    {!! $message->message !!}
                </div>
            </div>

            {{-- Anhaenge --}}
            @if ($message->files?->count())
                <div class="mt-6 px-2">
                    <h4 class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-200">
                        <i class="far fa-paperclip" aria-hidden="true"></i>
                        {{ __('app.attachments') }} ({{ $message->files->count() }})
                    </h4>

                    <div class="mx-2 my-6">
                        <ul class="space-y-2">
                            @foreach ($message->files as $f)
                                <li class="items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 md:flex">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <img src="{{ $f->icon_or_thumbnail }}" class="h-8 w-8 rounded border border-slate-200 object-cover dark:border-slate-600" alt="">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $f->name_with_extension }}</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ $f->getMimeTypeForHumans() }} &middot; {{ $f->size_formatted }}</div>
                                        </div>
                                    </div>
                                    <div class="mt-4 flex shrink-0 items-center gap-2 md:mt-0">
                                        {{-- Oeffnen (temporaere URL) --}}
                                        <a
                                            href="{{ $f->getEphemeralPublicUrl(10) }}"
                                            target="_blank"
                                            rel="noopener"
                                            title="{{ __('app.open_in_new_tab') }}"
                                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                                        >
                                            <i class="fal fa-external-link-alt" aria-hidden="true"></i>
                                            <span>{{ __('app.open') }}</span>
                                        </a>

                                        {{-- Herunterladen --}}
                                        <button
                                            type="button"
                                            title="{{ __('app.download') }}"
                                            wire:click="downloadFile({{ $f->id }})"
                                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                                        >
                                            <i class="fal fa-download" aria-hidden="true"></i>
                                            <span>{{ __('app.download') }}</span>
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @else
                <div class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_attachments') }}</div>
            @endif
        @else
            <div class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_message_selected') }}</div>
        @endif
    </x-slot>

    {{-- Footer --}}
    <x-slot name="footer">
        <button
            type="button"
            class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:ring-offset-slate-900"
            wire:click="$set('{{ $model }}', false)"
        >
            {{ __('app.close') }}
        </button>
    </x-slot>
</x-dialog-modal>
