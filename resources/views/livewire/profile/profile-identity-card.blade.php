<section
    class="relative overflow-hidden rounded-2xl border border-rt-border/80 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/80 dark:bg-rt-dark-surface"
    data-anim="fade-up"
    data-profile-identity
    data-autosave-scope
>
    <x-ui.autosave-status
        event="identity-saved"
        target="saveIdentity"
        dirty-target="name,email"
    />

    <div class="flex min-w-0 items-center gap-4 bg-rt-surface px-5 py-5 dark:bg-rt-dark-surface sm:px-6 sm:py-6">
        <div class="shrink-0">
            <div
                x-data="{ preview: null }"
                class="relative shrink-0"
                data-autosave-field
                data-autosave-model="photo"
                data-autosave-field-id="profile-identity-photo"
                data-autosave-state="idle"
            >
                <input
                    id="profile-identity-photo"
                    x-ref="photo"
                    type="file"
                    class="sr-only"
                    accept="image/*"
                    wire:model="photo"
                    data-autosave-model="photo"
                    data-autosave-field-id="profile-identity-photo"
                    data-autosave-instant="true"
                    x-on:change="
                        const file = $refs.photo.files?.[0];
                        if (!file) return;
                        const reader = new FileReader();
                        reader.onload = event => preview = event.target.result;
                        reader.readAsDataURL(file);
                    "
                >

                <button
                    type="button"
                    x-on:click="$refs.photo.click()"
                    class="rt-inline-edit-visual group/photo relative block rounded-2xl outline-none"
                    aria-label="{{ __('app.change_profile_photo') }}"
                    data-autosave-visual
                >
                    <img
                        x-show.important="! preview"
                        src="{{ $this->user->profile_photo_url }}"
                        alt="{{ $this->user->name }}"
                        class="h-20 w-20 rounded-2xl object-cover shadow-rt-sm ring-1 ring-rt-border/80 dark:ring-rt-dark-border/80"
                    >
                    <span
                        x-cloak
                        x-show.important="preview"
                        class="block h-20 w-20 rounded-2xl bg-cover bg-center shadow-rt-sm ring-1 ring-rt-border/80 dark:ring-rt-dark-border/80"
                        x-bind:style="`background-image: url('${preview}')`"
                        aria-hidden="true"
                    ></span>
                    <span class="absolute inset-0 flex items-center justify-center rounded-2xl bg-slate-950/0 text-white opacity-0 transition group-hover/photo:bg-slate-950/45 group-hover/photo:opacity-100 group-focus-visible/photo:bg-slate-950/45 group-focus-visible/photo:opacity-100">
                        <i class="far fa-camera" aria-hidden="true"></i>
                    </span>
                </button>

                @if($this->user->profile_photo_path)
                    <button
                        type="button"
                        wire:click="deleteProfilePhoto"
                        data-autosave-action-model="photo"
                        data-autosave-model="photo"
                        data-autosave-field-id="profile-identity-photo"
                        data-autosave-instant="true"
                        class="absolute -bottom-2 -right-2 inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-surface text-rt-muted shadow-rt-sm ring-1 ring-rt-border transition hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border"
                        aria-label="{{ __('app.remove_profile_photo') }}"
                    >
                        <i class="far fa-trash-alt text-[11px]" aria-hidden="true"></i>
                    </button>
                @endif
            </div>
        </div>

        <div
            x-data="{
                editor: null,
                verifying: false,
                openEditor(field) {
                    this.editor = field;
                    this.$nextTick(() => {
                        const input = field === 'name'
                            ? this.$refs.nameInput
                            : this.$refs.emailInput;

                        input?.focus();
                    });
                },
                closeEditor(field = null) {
                    if (field === null || this.editor === field) {
                        this.editor = null;
                    }
                },
                async requestEmailVerification() {
                    if (this.verifying) return;
                    this.verifying = true;

                    try {
                        if (this.editor === 'email') {
                            this.$refs.emailInput?.blur();
                        }

                        const scope = this.$el.closest('[data-autosave-scope]');
                        const detail = { scope, promise: null };
                        scope?.dispatchEvent(new CustomEvent('rt-autosave-flush', {
                            bubbles: true,
                            detail,
                        }));

                        await (detail.promise ?? Promise.resolve(true));

                        const state = this.$refs.emailField?.dataset.autosaveState ?? 'idle';
                        if (state === 'dirty' || state === 'saving') return;

                        await this.$wire.sendEmailVerification();
                    } finally {
                        this.verifying = false;
                    }
                },
            }"
            x-on:click.outside="closeEditor()"
            x-on:focusin.window="if (! $el.contains($event.target)) closeEditor()"
            class="min-w-0 flex-1"
            data-profile-inline-identity
        >
            <div
                class="min-w-0"
                data-autosave-field
                data-autosave-model="name"
                data-autosave-field-id="profile-identity-name"
                data-autosave-state="idle"
            >
                <button
                    x-show.important="editor !== 'name'"
                    type="button"
                    x-on:click="openEditor('name')"
                    class="rt-inline-edit-visual group/edit flex max-w-full items-center gap-2 rounded-lg text-left outline-none"
                    aria-label="{{ __('app.username') }} {{ __('app.edit') }}"
                    data-autosave-visual
                >
                    <span class="truncate text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                        {{ $name }}
                    </span>
                    <i class="far fa-pen text-[10px] text-rt-soft opacity-0 transition group-hover/edit:opacity-100 group-focus-visible/edit:opacity-100 dark:text-rt-dark-soft" aria-hidden="true"></i>
                </button>

                <x-ui.forms.input
                    x-cloak
                    x-show.important="editor === 'name'"
                    x-ref="nameInput"
                    id="profile-identity-name"
                    type="text"
                    wire:model="name"
                    x-on:blur="closeEditor('name')"
                    x-on:keydown.enter.prevent="$el.blur()"
                    autocomplete="name"
                    required
                    class="font-semibold"
                />
                <x-input-error for="name" class="mt-2" />
            </div>

            <div
                x-ref="emailField"
                class="mt-1.5 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5"
                data-autosave-field
                data-autosave-model="email"
                data-autosave-field-id="profile-identity-email"
                data-autosave-state="idle"
            >
                <button
                    x-show.important="editor !== 'email'"
                    type="button"
                    x-on:click="openEditor('email')"
                    class="rt-inline-edit-visual group/edit flex min-w-0 max-w-full items-center gap-2 rounded-md text-left text-sm text-rt-muted outline-none dark:text-rt-dark-muted"
                    aria-label="{{ __('app.email') }} {{ __('app.edit') }}"
                    data-autosave-visual
                >
                    <span class="truncate">{{ $email }}</span>
                    <i class="far fa-pen text-[9px] text-rt-soft opacity-0 transition group-hover/edit:opacity-100 group-focus-visible/edit:opacity-100 dark:text-rt-dark-soft" aria-hidden="true"></i>
                </button>

                <x-ui.forms.input
                    x-cloak
                    x-show.important="editor === 'email'"
                    x-ref="emailInput"
                    id="profile-identity-email"
                    type="email"
                    wire:model="email"
                    x-on:blur="closeEditor('email')"
                    x-on:keydown.enter.prevent="$el.blur()"
                    autocomplete="username"
                    required
                />
                <x-input-error for="email" class="mt-2 basis-full" />

                <div class="flex shrink-0 items-center gap-2">
                    @if($this->user->hasVerifiedEmail())
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-300">
                            <i class="far fa-check-circle" aria-hidden="true"></i>
                            {{ __('app.email_verified') }}
                        </span>
                    @else
                        <button
                            type="button"
                            x-on:click.prevent="requestEmailVerification()"
                            x-bind:disabled="verifying"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-600 transition hover:text-amber-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/30 dark:text-amber-300"
                        >
                            <i
                                class="far"
                                x-bind:class="verifying ? 'fa-spinner-third animate-spin' : 'fa-paper-plane'"
                                aria-hidden="true"
                            ></i>
                            {{ __('app.verify_email_now') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($verificationLinkSent)
        <p class="border-t border-rt-border/70 bg-emerald-50/70 px-5 py-3 text-sm font-medium text-emerald-700 dark:border-rt-dark-border/70 dark:bg-emerald-500/10 dark:text-emerald-200 sm:px-6">
            {{ __('app.verification_link_sent') }}
        </p>
    @endif

    <x-input-error for="photo" class="px-5 pb-4 sm:px-6" />
</section>
