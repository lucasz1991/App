@section('guest-viewport', 'width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content')
<x-guest-layout>
    <section class="rt-ops mx-auto max-w-2xl px-4 py-8 sm:px-8 sm:py-16"
        x-data="rtWorkTimeTerminal({ identifyUrl: @js(route('api.operations.terminal.authenticate')), captureUrl: @js(route('api.operations.terminal.capture')) })"
        x-on:pagehide.window="clear()">
        <header class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-rt-border pb-5 dark:border-rt-dark-border">
            <h1 class="text-2xl font-semibold tracking-tight">Zeiterfassung</h1>
            <span class="text-sm font-medium text-rt-muted dark:text-rt-dark-muted">RailTime · Terminal</span>
        </header>
        <p x-cloak x-show="message" x-text="message" role="status" aria-atomic="true"
            class="mb-4 rounded-xl px-4 py-3 text-sm font-medium"
            :class="{
                'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100': tone === 'ok',
                'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100': tone === 'warning',
                'bg-red-50 text-red-900 dark:bg-red-950 dark:text-red-100': tone === 'error',
                'bg-rt-surface-muted text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text': tone === 'neutral'
            }"></p>
        <div class="rounded-2xl border border-rt-border bg-rt-surface p-5 shadow-rt-xs sm:p-7 dark:border-rt-dark-border dark:bg-rt-dark-surface">
            <form x-show="!token" x-on:submit.prevent="identify" class="space-y-5" x-bind:aria-busy="busy" autocomplete="off">
                <h2 class="text-lg font-semibold">Terminal freigeben</h2>
                <fieldset x-bind:disabled="busy" class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5 sm:col-span-2">
                        <x-ui.forms.label for="terminal-device" value="Terminal-ID" />
                        <x-ui.forms.input id="terminal-device" x-model.trim="terminalId" required autocomplete="off" autocapitalize="none" spellcheck="false" />
                    </div>
                    <div class="space-y-1.5">
                        <x-ui.forms.label for="terminal-employee" value="Mitarbeiter-ID" />
                        <x-ui.forms.input id="terminal-employee" x-model="userId" inputmode="numeric" pattern="[1-9][0-9]*" required autocomplete="off" />
                    </div>
                    <div class="space-y-1.5">
                        <x-ui.forms.label for="terminal-pin" value="PIN" />
                        <x-ui.forms.input id="terminal-pin" x-model="pin" type="password" inputmode="numeric" pattern="[0-9]{6,10}" minlength="6" maxlength="10" required autocomplete="off" />
                    </div>
                </fieldset>
                <x-ui.buttons.button-basic type="submit" mode="primary" x-bind:disabled="busy" class="w-full sm:w-auto">
                    <span x-text="busy ? 'Wird freigegeben …' : 'Freigeben'">Freigeben</span>
                </x-ui.buttons.button-basic>
            </form>
            <form x-cloak x-show="token" x-on:submit.prevent="capture" x-bind:aria-busy="busy" class="space-y-5">
                <h2 id="terminal-state" tabindex="-1" class="text-xl font-semibold tracking-tight" x-text="stateLabel"></h2>
                <div x-show="!active" class="space-y-1.5">
                    <x-ui.forms.label for="terminal-context" value="Arbeitskontext" />
                    <select id="terminal-context" x-model="choice" x-bind:disabled="busy" class="rt-ui-control min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2 text-base dark:border-rt-dark-border dark:bg-rt-dark-control">
                        <option value="internal">Interne Arbeit</option>
                        <template x-for="duty in choices" :key="duty.id"><option x-bind:value="String(duty.id)" x-text="duty.title"></option></template>
                    </select>
                </div>
                <fieldset x-show="active?.status === 'running' || active?.status === 'paused'" x-bind:disabled="busy" class="space-y-2">
                    <legend class="text-sm font-semibold">Uhraktion</legend>
                    <div class="flex flex-wrap gap-4">
                        <template x-for="option in (active?.status === 'running' ? [['pause', 'Pause starten'], ['stop', 'Arbeitszeit beenden']] : [['resume', 'Weiterarbeiten'], ['stop', 'Arbeitszeit beenden']])" :key="option[0]">
                            <label class="inline-flex min-h-11 items-center gap-2 text-sm font-medium">
                                <input type="radio" name="terminal-action" x-model="action" x-bind:value="option[0]" class="h-5 w-5 accent-rt-accent" />
                                <span x-text="option[1]"></span>
                            </label>
                        </template>
                    </div>
                </fieldset>
                <p x-show="active?.status === 'completed'" class="text-sm text-rt-muted dark:text-rt-dark-muted">Reiche die beendete Arbeitszeit zur Prüfung ein.</p>
                <p x-show="locationRequired" role="status" class="text-sm text-rt-muted dark:text-rt-dark-muted"
                    x-text="stage === 'locating' ? 'Standort wird für diese Erfassung geprüft …' : 'Für diesen Zugang ist die Standortprüfung freigegeben.'"></p>
                <div class="flex flex-wrap gap-2 border-t border-rt-border pt-4 dark:border-rt-dark-border">
                    <x-ui.buttons.button-basic type="submit" mode="primary" x-bind:disabled="busy" class="flex-1 sm:flex-none"><span x-text="submitLabel">Erfassen</span></x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" x-on:click="finish()" x-bind:disabled="busy" class="flex-1 sm:flex-none">Abbrechen</x-ui.buttons.button-basic>
                </div>
            </form>
        </div>
    </section>
</x-guest-layout>
