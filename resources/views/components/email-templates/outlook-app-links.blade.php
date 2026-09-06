@props(['id' => 'mail-outlook-app'])
<div
    id="{{ $id }}"
    x-data="railtimePwaInstall(@js([
        'messages' => [
            'installed' => __('app.help_installed'),
            'ready' => __('app.help_install_ready'),
            'manual' => __('app.push_install_manually_description'),
            'accepted' => __('app.push_install_accepted'),
            'failed' => __('app.push_install_failed'),
        ],
    ]))"
    class="flex flex-wrap items-center justify-center gap-2 border-t border-rt-border/60 pt-5 dark:border-rt-dark-border/60"
    data-outlook-app-links
    data-railtime-app-links
>
    <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-semibold text-rt-muted transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-external-link-alt" aria-hidden="true"></i> RailTime öffnen
    </a>
    <button
        type="button"
        x-cloak
        x-show="mode === 'prompt' || mode === 'installed' || busy"
        x-on:click="installApp()"
        x-bind:disabled="disabled"
        x-bind:aria-busy="busy"
        class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border/70 bg-rt-surface/75 px-4 text-sm font-semibold text-rt-text backdrop-blur-xl focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 disabled:cursor-default disabled:opacity-60 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/75 dark:text-rt-dark-text"
        data-railtime-install-action
    >
        <i class="far fa-download" aria-hidden="true"></i>
        <span x-text="mode === 'installed' ? 'RailTime bereits installiert' : 'RailTime installieren'">RailTime installieren</span>
    </button>
    <a
        href="{{ route('help') }}"
        target="_blank"
        rel="noopener noreferrer"
        x-show="mode !== 'prompt' && mode !== 'installed' && !busy"
        class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border/70 bg-rt-surface/75 px-4 text-sm font-semibold text-rt-text backdrop-blur-xl focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/75 dark:text-rt-dark-text"
        data-railtime-install-help
    >
        <i class="far fa-download" aria-hidden="true"></i> RailTime installieren
    </a>
    <p x-cloak x-show="notice" x-text="notice" role="status" aria-live="polite" class="w-full text-center text-xs text-rt-muted dark:text-rt-dark-muted"></p>
    <p x-cloak x-show="error" x-text="error" role="alert" class="w-full text-center text-xs text-rt-red dark:text-rt-dark-accent"></p>
</div>
