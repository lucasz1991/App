@props([
    'event' => 'saved',
    'target' => null,
    'dirtyTarget' => null,
    'floating' => true,
])

@php
    $dirtyTargets = collect(explode(',', (string) $dirtyTarget))
        ->map(fn ($item) => trim($item))
        ->filter()
        ->values()
        ->all();
    $saveMethod = trim(explode(',', (string) $target)[0] ?? '');
@endphp

<span
    x-data="{
        saved: false,
        dirty: false,
        saving: false,
        visible: false,
        pendingSave: null,
        timer: null,
        hideTimer: null,
        scope: null,
        targets: {{ \Illuminate\Support\Js::from($dirtyTargets) }},
        saveMethod: @js($saveMethod),
        init() {
            this.scope = this.$el.closest('[data-autosave-scope], form, section');
        },
        fieldModel(field) {
            return [
                'wire:model',
                'wire:model.live',
                'wire:model.blur',
                'wire:model.lazy',
            ].map(attribute => field?.getAttribute?.(attribute)).find(Boolean);
        },
        matchesField(field) {
            const model = this.fieldModel(field);
            return model && this.targets.some(target => model === target || model.startsWith(`${target}.`));
        },
        schedule() {
            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.flush().catch(() => {}), 3000);
        },
        markDirty(event) {
            if (!this.matchesField(event.target)) {
                return;
            }

            this.dirty = true;
            this.saved = false;
            this.visible = true;
            window.clearTimeout(this.hideTimer);
            this.schedule();
        },
        continueIdleCountdown(event) {
            if (!this.dirty) return;
            if (!this.scope?.contains(event.target)) {
                this.flush().catch(() => {});
                return;
            }
            this.schedule();
        },
        async flush() {
            if (!this.dirty || !this.saveMethod) return;
            if (this.saving) return this.pendingSave;

            window.clearTimeout(this.timer);
            this.saving = true;
            this.visible = true;
            this.pendingSave = this.$wire.call(this.saveMethod).finally(() => {
                this.saving = false;
                this.pendingSave = null;
            });

            return this.pendingSave;
        },
        handleOutside(event) {
            if (!this.dirty || this.scope?.contains(event.target)) return;
            this.flush().catch(() => {});
        },
        async handleOutsideClick(event) {
            if (!this.dirty || this.scope?.contains(event.target)) return;

            const link = event.target.closest?.('a[href]');
            if (!link || link.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            await this.flush();
            window.location.assign(link.href);
        },
        showSavedFeedback() {
            this.dirty = false;
            this.saved = true;
            this.saving = false;
            window.clearTimeout(this.timer);
            window.clearTimeout(this.hideTimer);

            this.visible = true;
            window.RTSound?.play('success');

            this.hideTimer = window.setTimeout(() => {
                this.visible = false;
                this.saved = false;
            }, 1350);
        },
    }"
    x-on:input.window="markDirty($event)"
    x-on:change.window="markDirty($event)"
    x-on:focusin.window="continueIdleCountdown($event)"
    x-on:pointerdown.window.capture="handleOutside($event)"
    x-on:click.window.capture="handleOutsideClick($event)"
    x-on:beforeunload.window="flush()"
    x-on:{{ $event }}.window="showSavedFeedback()"
    @class([
        'inline-flex h-7 w-7 items-center justify-center rounded-full bg-rt-surface/90 shadow-rt-xs ring-1 ring-rt-border/80 backdrop-blur transition duration-200 ease-rt-spring dark:bg-rt-dark-surface/90 dark:ring-rt-dark-border/80',
        'absolute right-4 top-4 z-10' => $floating,
    ])
    x-bind:class="visible
        ? 'scale-100 opacity-100'
        : 'pointer-events-none scale-90 opacity-0'"
    aria-live="polite"
    x-bind:aria-hidden="visible ? 'false' : 'true'"
    x-bind:aria-label="dirty || saving
        ? @js(__('app.unsaved_changes'))
        : (saved ? @js(__('app.saved_automatically')) : '')"
    x-bind:title="dirty || saving
        ? @js(__('app.unsaved_changes'))
        : (saved ? @js(__('app.saved_automatically')) : '')"
    data-autosave-status="{{ $event }}"
>
    <span
        class="h-2.5 w-2.5 rounded-full transition-all duration-200"
        x-bind:class="dirty || saving
            ? 'bg-amber-400 shadow-[0_0_0_4px_rgba(251,191,36,0.14)]'
            : (saved
                ? 'bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,0.12)]'
                : 'bg-transparent shadow-none')"
        x-bind:data-state="dirty || saving ? 'dirty' : (saved ? 'saved' : 'idle')"
        aria-hidden="true"
    ></span>
</span>
