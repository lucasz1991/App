<div class="space-y-4">
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
        <div class="flex items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-sky-700 shadow-sm dark:bg-sky-400/10 dark:text-sky-200">
                <i class="far fa-folder-lock" aria-hidden="true"></i>
            </span>
            <div>
                <p class="font-semibold">{{ __('app.employee_documents') }}</p>
                <p class="mt-1 text-xs leading-5 opacity-80">{{ __('app.employee_documents_hint') }}</p>
            </div>
        </div>
    </div>

    @php
        $documentIcons = [
            'identity_card' => 'fa-id-card',
            'drivers_license' => 'fa-car',
            'health_insurance_card' => 'fa-heartbeat',
            'bank_card' => 'fa-credit-card',
            'employment_contract' => 'fa-file-signature',
            'pension_exemption' => 'fa-file-certificate',
        ];
    @endphp

    <div class="grid gap-3 lg:grid-cols-2">
        @foreach ($types as $type => $label)
            @php
                $requirement = $requirements->get($type);
                $file = $requirement?->file;
                $inputId = 'employee-document-'.$userId.'-'.$type;
            @endphp

            <section
                wire:key="employee-document-{{ $type }}"
                class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
            >
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-accent dark:bg-rt-dark-surface-muted dark:text-rt-dark-accent">
                        <i class="far {{ $documentIcons[$type] ?? 'fa-file-alt' }}" aria-hidden="true"></i>
                    </span>
                    <h3 class="min-w-0 flex-1 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $label }}</h3>
                </div>

                @if ($file)
                    <div class="mt-4 rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-rt-muted shadow-sm dark:bg-rt-dark-surface dark:text-rt-dark-muted">
                                <i class="far fa-file-check" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $file->name }}</p>
                                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                                    {{ $file->getMimeTypeForHumans() }} · {{ $file->size_formatted }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                        <button
                            type="button"
                            wire:click="download('{{ $type }}')"
                            class="inline-flex min-h-9 items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-rt-accent transition hover:bg-rt-accent-soft focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:text-rt-dark-accent dark:hover:bg-rt-dark-accent-soft"
                        >
                            <i class="far fa-download" aria-hidden="true"></i>
                            {{ __('app.download') }}
                        </button>

                        @if ($canEdit)
                            <label for="{{ $inputId }}" class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg bg-rt-accent px-3 py-2 text-xs font-semibold text-white shadow-rt-xs transition hover:brightness-95 focus-within:ring-2 focus-within:ring-rt-accent/35 dark:bg-rt-dark-accent dark:text-slate-950">
                                <i class="far fa-sync-alt" aria-hidden="true"></i>
                                {{ __('app.replace_file') }}
                            </label>
                            <button
                                type="button"
                                wire:click="remove('{{ $type }}')"
                                wire:confirm="{{ __('app.employee_document_remove_confirm') }}"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400/35 dark:text-red-300 dark:hover:bg-red-500/10"
                                title="{{ __('app.delete') }}"
                            >
                                <i class="far fa-trash-alt" aria-hidden="true"></i>
                            </button>
                        @endif
                    </div>
                @elseif ($canEdit)
                    <label
                        for="{{ $inputId }}"
                        class="mt-4 flex min-h-28 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/60 px-4 py-5 text-center transition hover:border-rt-accent/55 hover:bg-rt-accent-soft/45 focus-within:ring-2 focus-within:ring-rt-accent/25 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/55 dark:hover:border-rt-dark-accent/55 dark:hover:bg-rt-dark-accent-soft/45"
                    >
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-rt-accent shadow-sm dark:bg-rt-dark-surface dark:text-rt-dark-accent">
                            <i class="far fa-cloud-upload" aria-hidden="true"></i>
                        </span>
                        <span class="mt-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.add_document') }}</span>
                        <span class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.employee_document_formats') }}</span>
                    </label>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-rt-border px-4 py-5 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">
                        <i class="far fa-file-slash mb-2 block text-lg" aria-hidden="true"></i>
                        {{ __('app.no_file_linked') }}
                    </div>
                @endif

                @if ($canEdit)
                    <input
                        id="{{ $inputId }}"
                        type="file"
                        wire:model="uploads.{{ $type }}"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                        class="sr-only"
                    >

                    <div wire:loading wire:target="uploads.{{ $type }}" class="mt-3 text-xs font-medium text-rt-accent dark:text-rt-dark-accent">
                        <i class="far fa-spinner-third mr-1 animate-spin" aria-hidden="true"></i>
                        {{ __('app.uploading') }}
                    </div>

                    @if (isset($uploads[$type]) && $uploads[$type])
                        <div class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-rt-border bg-rt-surface-muted px-3 py-2 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
                            <span class="min-w-0 truncate text-xs font-medium text-rt-text dark:text-rt-dark-text">{{ $uploads[$type]->getClientOriginalName() }}</span>
                            <x-ui.buttons.button-basic size="sm" wire:click="save('{{ $type }}')" wire:loading.attr="disabled" wire:target="save('{{ $type }}')">
                                <i class="far fa-upload" aria-hidden="true"></i>
                                {{ __('app.save') }}
                            </x-ui.buttons.button-basic>
                        </div>
                    @endif

                    @error('uploads.'.$type)
                        <p class="mt-2 text-xs font-medium text-red-600 dark:text-red-300">{{ $message }}</p>
                    @enderror
                @endif
            </section>
        @endforeach
    </div>
</div>
