<section
    class="group relative overflow-hidden rounded-[1.65rem] bg-rt-surface-muted p-1.5 shadow-rt-md ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70"
    data-anim="fade-up"
    data-profile-identity
>
    <div
        class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-rt-accent/10 blur-3xl dark:bg-rt-dark-accent/10"
        aria-hidden="true"
    ></div>

    <div class="relative overflow-hidden rounded-[calc(1.65rem-4px)] bg-rt-surface px-5 py-5 dark:bg-rt-dark-surface sm:px-7 sm:py-6">
        <div class="grid min-w-0 gap-6 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center">
            <div
                x-data="{ preview: null }"
                class="relative mx-auto w-fit lg:mx-0"
            >
                <input
                    id="profile-identity-photo"
                    x-ref="photo"
                    type="file"
                    class="sr-only"
                    accept="image/*"
                    wire:model="photo"
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
                    class="relative block rounded-[1.35rem] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/20"
                    aria-label="{{ __('app.change_profile_photo') }}"
                >
                    <img
                        x-show.important="! preview"
                        src="{{ $this->user->profile_photo_url }}"
                        alt="{{ $this->user->name }}"
                        class="h-24 w-24 rounded-[1.35rem] object-cover shadow-rt-md ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70 sm:h-28 sm:w-28"
                    >
                    <span
                        x-cloak
                        x-show.important="preview"
                        class="block h-24 w-24 rounded-[1.35rem] bg-cover bg-center shadow-rt-md ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70 sm:h-28 sm:w-28"
                        x-bind:style="`background-image: url('${preview}')`"
                        aria-hidden="true"
                    ></span>
                    <span class="absolute inset-x-2 bottom-2 inline-flex min-h-9 items-center justify-center gap-2 rounded-xl bg-slate-950/72 px-3 py-2 text-xs font-semibold text-white shadow-lg backdrop-blur-md transition group-hover:bg-rt-accent">
                        <i class="far fa-camera" aria-hidden="true"></i>
                        {{ __('app.edit') }}
                    </span>
                </button>

                @if($this->user->profile_photo_path)
                    <button
                        type="button"
                        wire:click="deleteProfilePhoto"
                        class="absolute -right-2 -top-2 inline-flex h-9 w-9 items-center justify-center rounded-xl bg-rt-surface text-rt-muted shadow-rt-sm ring-1 ring-rt-border transition hover:text-rt-accent focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/20 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border dark:hover:text-rt-dark-accent"
                        aria-label="{{ __('app.remove_profile_photo') }}"
                    >
                        <i class="far fa-trash-alt text-xs" aria-hidden="true"></i>
                    </button>
                @endif
            </div>

            <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="profile-identity-name" class="mb-1.5 flex items-center gap-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                        <i class="far fa-user text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                        {{ __('app.username') }}
                    </label>
                    <x-ui.forms.input
                        id="profile-identity-name"
                        type="text"
                        wire:model="name"
                        wire:blur="saveIdentity"
                        autocomplete="name"
                        required
                        class="font-semibold"
                    />
                    <x-input-error for="name" class="mt-2" />
                </div>

                <div class="min-w-0">
                    <label for="profile-identity-email" class="mb-1.5 flex items-center gap-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                        <i class="far fa-envelope text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                        {{ __('app.email') }}
                    </label>
                    <x-ui.forms.input
                        id="profile-identity-email"
                        type="email"
                        wire:model="email"
                        wire:blur="saveIdentity"
                        autocomplete="username"
                        required
                        class="font-semibold"
                    />
                    <x-input-error for="email" class="mt-2" />
                </div>
            </div>

            <div class="flex min-w-0 flex-col items-center gap-3 lg:items-end">
                @if($this->user->hasVerifiedEmail())
                    <span class="inline-flex w-fit items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                        <i class="far fa-check-circle" aria-hidden="true"></i>
                        {{ __('app.email_verified') }}
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="sendEmailVerification"
                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-amber-50 px-3.5 py-2 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 transition hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30 dark:hover:bg-amber-500/15"
                    >
                        <i class="far fa-paper-plane" aria-hidden="true"></i>
                        {{ __('app.verify_email_now') }}
                    </button>
                @endif

                <x-ui.autosave-status
                    event="identity-saved"
                    target="saveIdentity,photo,deleteProfilePhoto"
                    dirty-target="name,email"
                />
            </div>
        </div>

        @if($verificationLinkSent)
            <p class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/25">
                {{ __('app.verification_link_sent') }}
            </p>
        @endif

        <x-input-error for="photo" class="mt-3" />
    </div>
</section>
