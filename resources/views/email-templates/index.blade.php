@extends('layouts.master')

@section('title', __('app.email_templates'))

@section('content')
    @php
        $user = auth()->user();
        $templateBuilder = new \App\Support\EmailTemplateBuilder($user);
        $templateValues = $templateBuilder->profileValues();
        $availableTemplates = \App\Support\EmailTemplateBuilder::available();
        $missingContactData = $templateValues['DURCHWAHL'] === '' || blank($user->profile?->position);
        $previewUrl = route('email-templates.preview', ['template' => 'vorlage-html']);
    @endphp

    <x-ui.page
        :title="__('app.email_templates')"
        :description="__('app.email_templates_intro')"
        :eyebrow="__('app.personal_data')"
        :count="count($availableTemplates)"
    >
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.35fr)]">
            <aside class="space-y-4 xl:sticky xl:top-24" data-anim="fade-up">
                <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
                    <div class="border-b border-rt-border/70 bg-rt-surface-muted px-5 py-5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted">
                        <div class="flex items-center gap-3">
                            <img
                                src="{{ $user->profile_photo_url }}"
                                alt="{{ $user->name }}"
                                class="h-11 w-11 rounded-xl object-cover shadow-rt-xs ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                            >
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">
                                    {{ __('app.profile_status') }}
                                </p>
                                <h2 class="mt-1 truncate text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                                    {{ $user->name }}
                                </h2>
                            </div>
                        </div>
                    </div>

                    <dl class="divide-y divide-rt-border/70 px-5 dark:divide-rt-dark-border/70">
                        <div class="py-3.5">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-rt-soft dark:text-rt-dark-soft">{{ __('app.position') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['POSITION'] }}</dd>
                        </div>
                        <div class="py-3.5">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-rt-soft dark:text-rt-dark-soft">{{ __('app.email') }}</dt>
                            <dd class="mt-1 break-all text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['E_MAIL'] }}</dd>
                        </div>
                        <div class="py-3.5">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-rt-soft dark:text-rt-dark-soft">{{ __('app.phone') }} / {{ __('app.mobile') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-rt-text dark:text-rt-dark-text">
                                {{ $templateValues['DURCHWAHL'] !== '' ? $templateValues['DURCHWAHL'] : '—' }}
                                <span class="px-1 text-rt-soft" aria-hidden="true">&middot;</span>
                                {{ $templateValues['MOBIL'] !== '' ? $templateValues['MOBIL'] : '—' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="px-5 pb-5 pt-1">
                        @if ($missingContactData)
                            <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                                <div class="flex items-start gap-2.5">
                                    <i class="far fa-info-circle mt-0.5" aria-hidden="true"></i>
                                    <p class="leading-5">{{ __('app.email_templates_missing_data') }}</p>
                                </div>
                                <a
                                    href="{{ route('profile.show') }}"
                                    class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-amber-900 underline decoration-amber-500/50 underline-offset-4 transition hover:decoration-current dark:text-amber-200"
                                >
                                    {{ __('app.complete_profile') }}
                                    <i class="far fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        @else
                            <div class="flex items-start gap-2.5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                                <i class="far fa-check-circle mt-0.5" aria-hidden="true"></i>
                                <p class="leading-5">{{ __('app.profile_complete_hint') }}</p>
                            </div>
                        @endif
                    </div>
                </section>

                <p class="rounded-xl bg-rt-surface-muted px-4 py-3 text-xs leading-5 text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                    {{ __('app.email_templates_legal_hint') }}
                </p>
            </aside>

            <section
                x-data="{ previewOpen: false }"
                class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                data-anim="fade-up"
                data-anim-delay="0.05"
            >
                <header class="flex items-center justify-between gap-4 border-b border-rt-border/70 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted sm:px-6">
                    <div>
                        <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                            {{ __('app.downloads') }}
                        </h2>
                        <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.email_templates_intro') }}
                        </p>
                    </div>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="far fa-file-download" aria-hidden="true"></i>
                    </span>
                </header>

                <div class="border-b border-rt-border/70 px-5 py-5 sm:px-6 dark:border-rt-dark-border/70">
                    <div class="grid items-center gap-4 md:grid-cols-[minmax(0,1fr)_minmax(13rem,0.62fr)]">
                        <button
                            type="button"
                            @click="previewOpen = true"
                            class="group/preview relative h-48 w-full overflow-hidden rounded-xl bg-slate-200 text-left shadow-inner ring-1 ring-rt-border/70 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50 dark:bg-slate-900 dark:ring-rt-dark-border/70"
                            aria-label="{{ __('app.preview') }}"
                        >
                            <iframe
                                src="{{ $previewUrl }}"
                                title="{{ __('app.preview') }}"
                                sandbox=""
                                tabindex="-1"
                                aria-hidden="true"
                                class="pointer-events-none absolute left-0 top-0 border-0 bg-white"
                                style="width:500%;height:960px;transform:scale(.2);transform-origin:top left;"
                            ></iframe>
                            <span class="absolute inset-0 flex items-center justify-center bg-slate-950/0 transition group-hover/preview:bg-slate-950/25 group-focus-visible/preview:bg-slate-950/25">
                                <span class="flex translate-y-2 items-center gap-2 rounded-full bg-slate-950/80 px-4 py-2 text-xs font-semibold text-white opacity-0 shadow-lg transition group-hover/preview:translate-y-0 group-hover/preview:opacity-100 group-focus-visible/preview:translate-y-0 group-focus-visible/preview:opacity-100">
                                    <i class="far fa-expand" aria-hidden="true"></i>
                                    {{ __('app.preview') }}
                                </span>
                            </span>
                        </button>

                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">{{ __('app.preview') }}</p>
                            <h3 class="mt-1 text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.email_template_mail_html') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_template_mail_html_hint') }}</p>
                            <button
                                type="button"
                                @click="previewOpen = true"
                                class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-accent"
                            >
                                <i class="far fa-expand-alt" aria-hidden="true"></i>
                                {{ __('app.preview_enlarged') }}
                            </button>
                        </div>
                    </div>
                </div>

                <ul class="divide-y divide-rt-border/70 dark:divide-rt-dark-border/70">
                    @foreach ($availableTemplates as $key => $template)
                        <li class="group flex flex-col gap-4 px-5 py-5 transition-colors duration-200 hover:bg-rt-surface-muted/80 dark:hover:bg-rt-dark-surface-muted/70 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="flex min-w-0 items-start gap-3.5">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-red ring-1 ring-rt-border/70 transition-colors duration-200 group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:text-rt-dark-accent dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                    <i class="far {{ str_starts_with($key, 'vorlage') ? 'fa-envelope-open-text' : 'fa-signature' }}" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __($template['label']) }}</h3>
                                        <span class="rounded-md bg-rt-surface-muted px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                                            {{ $template['extension'] }}
                                        </span>
                                    </div>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __($template['hint']) }}</p>
                                </div>
                            </div>

                            <a
                                href="{{ route('email-templates.download', ['template' => $key]) }}"
                                class="inline-flex w-fit shrink-0 items-center justify-center gap-2 rounded-lg bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark hover:shadow-rt-sm active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface"
                            >
                                <i class="far fa-download" aria-hidden="true"></i>
                                {{ __('app.download') }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <template x-teleport="body">
                    <div
                        x-cloak
                        x-show="previewOpen"
                        x-on:keydown.escape.window="previewOpen = false"
                        class="fixed inset-0 z-[180] flex items-center justify-center p-2 sm:p-5"
                        role="dialog"
                        aria-modal="true"
                        aria-label="{{ __('app.preview') }}"
                    >
                        <button type="button" class="absolute inset-0 bg-slate-950/65 backdrop-blur-sm" @click="previewOpen = false" aria-label="{{ __('app.close') }}"></button>
                        <div
                            x-show="previewOpen"
                            x-trap.inert.noscroll="previewOpen"
                            x-transition:enter="ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-3 scale-[0.98]"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            x-transition:leave="ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                            x-transition:leave-end="opacity-0 translate-y-3 scale-[0.98]"
                            class="relative flex h-[92dvh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-rt-surface shadow-2xl ring-1 ring-white/20 dark:bg-rt-dark-surface"
                        >
                            <header class="flex items-center justify-between gap-4 border-b border-rt-border/70 px-4 py-3 sm:px-5 dark:border-rt-dark-border/70">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ __('app.email_template_mail_html') }}</p>
                                    <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $user->name }}</p>
                                </div>
                                <button type="button" @click="previewOpen = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-rt-surface-muted text-rt-muted transition hover:text-rt-red dark:bg-rt-dark-surface-muted dark:text-white dark:hover:text-rt-dark-accent" aria-label="{{ __('app.close') }}">
                                    <i class="far fa-times" aria-hidden="true"></i>
                                </button>
                            </header>
                            <iframe
                                src="{{ $previewUrl }}"
                                title="{{ __('app.preview') }}"
                                sandbox=""
                                class="min-h-0 flex-1 border-0 bg-white"
                            ></iframe>
                            <footer class="flex items-center justify-end gap-2 border-t border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70">
                                <a
                                    href="{{ route('email-templates.download', ['template' => 'vorlage-html']) }}"
                                    class="inline-flex items-center gap-2 rounded-lg bg-rt-red px-4 py-2 text-sm font-semibold text-white transition hover:bg-rt-red-dark"
                                >
                                    <i class="far fa-download" aria-hidden="true"></i>
                                    {{ __('app.download') }}
                                </a>
                            </footer>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </x-ui.page>
@endsection
