@props(['id' => 'mail-outlook-app'])
<div x-data="{ installOpen: false }" class="flex flex-wrap items-center justify-center gap-2 border-t border-rt-border/60 pt-5 dark:border-rt-dark-border/60" data-outlook-app-links>
    <a href="https://outlook.office.com/mail/" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-semibold text-rt-muted transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted">
        <i class="far fa-external-link-alt" aria-hidden="true"></i> Outlook öffnen
    </a>
    <button type="button" x-on:click="installOpen = true" aria-haspopup="dialog" aria-controls="{{ $id }}-install" x-bind:aria-expanded="installOpen" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border/70 bg-rt-surface/75 px-4 text-sm font-semibold text-rt-text backdrop-blur-xl focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/75 dark:text-rt-dark-text">
        <i class="far fa-download" aria-hidden="true"></i> Outlook als App installieren
    </button>
    <x-ui.state-modal :id="$id.'-install'" state="installOpen" title="Outlook als Browser-App" icon="fab fa-microsoft" max-width="2xl">
        <div class="space-y-4 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            <p>Die Installation wird auf der Microsoft-Seite im Browser bestätigt. RailTime kann diesen Dialog nicht für eine fremde Website auslösen.</p>
            <ol class="list-decimal space-y-2 pl-5">
                <li><a href="https://outlook.office.com/mail/" target="_blank" rel="noopener noreferrer" class="font-semibold text-rt-red underline underline-offset-4">Outlook in Microsoft Edge öffnen</a> und anmelden.</li>
                <li>Im Edge-Menü „…“ → „Apps“ → „Diese Site als eine App installieren“ wählen.</li>
                <li>In Chrome die angebotene Installations-Schaltfläche in der Adressleiste verwenden.</li>
            </ol>
            <p class="text-xs">Die Browser-App ersetzt nicht die Zuweisung des RailTime-Add-ins. Auf iPhone/iPad bitte einen unterstützten Outlook-Client verwenden; Apple Mail übernimmt das Add-in nicht.</p>
            <a href="https://support.microsoft.com/en-us/outlook/use-the-web-version-of-outlook-like-a-desktop-app" target="_blank" rel="noopener noreferrer" class="text-xs underline underline-offset-4">Microsoft-Anleitung öffnen</a>
        </div>
    </x-ui.state-modal>
</div>
